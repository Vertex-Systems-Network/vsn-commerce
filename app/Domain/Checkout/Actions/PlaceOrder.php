<?php

namespace App\Domain\Checkout\Actions;

use App\Domain\Affiliate\Actions\AccrueAffiliateCommissions;
use App\Domain\Checkout\Exceptions\CheckoutValidationException;
use App\Domain\Finance\Actions\PostOrderFinance;
use App\Domain\Inventory\Actions\ConvertInventoryReservation;
use App\Domain\Gifts\Actions\FinalizeGiftOrder;
use App\Domain\Wallet\Actions\CaptureWalletHold;
use App\Domain\Reviews\Actions\RedeemReviewCoupon;
use App\Domain\Promotions\Actions\RedeemCheckoutPromotions;
use App\Domain\Tax\Actions\SnapshotOrderTaxes;
use App\Domain\Tax\Actions\IssueOrderInvoices;
use App\Domain\Wallet\Exceptions\WalletException;
use App\Enums\CartStatus;
use App\Enums\CheckoutStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Defines the PlaceOrder class and its project responsibilities. */
class PlaceOrder
{
    /** Initializes the PlaceOrder instance and its dependencies. */
    public function __construct(
        private readonly ConvertInventoryReservation $convertReservation,
        private readonly CaptureWalletHold $captureWalletHold,
        private readonly AccrueAffiliateCommissions $accrueAffiliateCommissions,
        private readonly FinalizeGiftOrder $finalizeGiftOrder,
        private readonly RedeemReviewCoupon $redeemReviewCoupon,
        private readonly RedeemCheckoutPromotions $redeemPromotions,
        private readonly PostOrderFinance $postOrderFinance,
        private readonly SnapshotOrderTaxes $snapshotOrderTaxes,
        private readonly IssueOrderInvoices $issueOrderInvoices,
    ) {}

    /** Executes the place order operation. */
    public function execute(User $user, CheckoutSession $session, PaymentStatus $paymentStatus = PaymentStatus::Pending): Order
    {
        if ($session->payment_method === 'coins') {
            if ($session->total_minor !== 0 || $session->coin_redemption_coins <= 0) {
                throw new CheckoutValidationException('VSN Coins payment is not fully funded.', 'payment');
            }
            $paymentStatus = PaymentStatus::Paid;
        }
        if ($session->payment_method !== 'cod' && $session->payment_method !== 'coins' && $paymentStatus !== PaymentStatus::Paid) {
            throw new CheckoutValidationException('Online-payment orders can only be finalized after a verified payment webhook.', 'payment');
        }

        $existing = Order::query()->where('checkout_session_id', $session->id)->first();
        if ($existing) {
            abort_unless($existing->user_id === $user->id, 404);
            if ($paymentStatus === PaymentStatus::Paid && $existing->payment_status !== PaymentStatus::Paid) {
                $existing->update(['payment_status' => PaymentStatus::Paid]);
            }
            $existing = $this->load($existing);
            if (($session->metadata['coupon_type'] ?? null) === 'review_reward') {
                $this->redeemReviewCoupon->execute($user, $session, $existing);
            }
            $this->redeemPromotions->execute($session, $existing);
            $this->snapshotOrderTaxes->execute($existing);
            $this->issueTaxInvoicesSafely($existing);
            $this->postOrderFinanceSafely($existing);
            $this->finalizeGiftSafely($existing);
            $this->accrueAffiliateSafely($existing);
            return $existing;
        }

        $order = DB::transaction(/** Inline callback for this operation. */ function () use ($user, $session, $paymentStatus): Order {
            $session = CheckoutSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_unless($session->user_id === $user->id, 404);

            $existing = Order::query()->where('checkout_session_id', $session->id)->first();
            if ($existing) {
                if ($paymentStatus === PaymentStatus::Paid && $existing->payment_status !== PaymentStatus::Paid) {
                    $existing->update(['payment_status' => PaymentStatus::Paid]);
                }
                return $this->load($existing);
            }

            if ($session->status !== CheckoutStatus::Reserved) {
                throw new CheckoutValidationException('This checkout session is no longer active.');
            }

            if ($session->expires_at->isPast()) {
                throw new CheckoutValidationException('The stock reservation expired. Refresh checkout to reserve inventory again.');
            }

            $session->load(['items.reservation', 'items.vendor', 'walletHold', 'promotionAllocations.promotion', 'promotionUsages', 'taxLines']);
            if ($session->items->isEmpty()) {
                throw new CheckoutValidationException('Checkout has no items.');
            }

            foreach ($session->items as $item) {
                if (! $item->reservation) {
                    throw new CheckoutValidationException('A checkout item is missing its inventory reservation.');
                }
            }

            $order = Order::create([
                'public_id' => (string) Str::ulid(),
                'user_id' => $user->id,
                'checkout_session_id' => $session->id,
                'status' => OrderStatus::Confirmed,
                'payment_status' => $paymentStatus,
                'payment_method' => $session->payment_method,
                'currency' => $session->currency,
                'subtotal_minor' => $session->subtotal_minor,
                'shipping_minor' => $session->shipping_minor,
                'discount_minor' => $session->discount_minor,
                'platform_discount_minor' => $session->platform_discount_minor,
                'seller_discount_minor' => $session->seller_discount_minor,
                'tax_minor' => $session->tax_minor, 'tax_included_minor'=>$session->tax_included_minor, 'tax_added_minor'=>$session->tax_added_minor,
                'coin_redemption_coins' => $session->coin_redemption_coins,
                'coin_redemption_minor' => $session->coin_redemption_minor,
                'total_minor' => $session->total_minor,
                'placed_at' => now(),
                'metadata' => [
                    'shipping_method' => $session->shipping_method,
                    'coupon_code' => $session->coupon_code,
                    'checkout_public_id' => $session->public_id,
                    'promotions' => $session->metadata['promotions'] ?? [],
                ],
            ]);

            if ($session->coin_redemption_coins > 0) {
                if (! $session->walletHold) {
                    throw new CheckoutValidationException('Checkout is missing its VSN Coins hold.');
                }
                try {
                    $walletTx = $this->captureWalletHold->execute(
                        $user, $session->walletHold, "checkout-redemption:{$session->public_id}", 'order', $order->public_id
                    );
                } catch (WalletException $exception) {
                    throw new CheckoutValidationException($exception->getMessage(), 'coinRedemptionCoins');
                }
                $order->update(['wallet_transaction_id'=>$walletTx->id]);
            }

            $order->shippingAddress()->create([
                'type' => 'shipping',
                ...$session->address_snapshot,
            ]);

            $groups = $session->items->groupBy(/** Inline callback for this operation. */ fn ($item) => (string) ($item->vendor_id ?? 0));
            $shippingAllocations = $this->allocate($session->shipping_minor, $groups->map(/** Inline callback for this operation. */ fn () => 1));
            
            foreach ($groups as $vendorKey => $items) {
                $vendorId = ((int) $vendorKey) ?: null;
                $vendor = $vendorId ? Vendor::query()->find($vendorId) : null;
                $subtotal = (int) $items->sum('line_total_minor');
                $shipping = $shippingAllocations[$vendorKey] ?? 0;
                $itemIds = $items->pluck('id');
                $vendorAllocations = $session->promotionAllocations->whereIn('checkout_session_item_id', $itemIds);
                $platformDiscount = (int) $vendorAllocations->sum('platform_funded_minor');
                $sellerDiscount = (int) $vendorAllocations->sum('seller_funded_minor');
                $discount = $platformDiscount + $sellerDiscount;
                $taxLines = $session->taxLines->where('vendor_id', $vendorId);
                if ($vendorId === null) $taxLines = $session->taxLines->filter(/** Inline callback for this operation. */ fn($x)=>$x->vendor_id===null);
                $taxMinor=(int)$taxLines->sum('tax_minor');
                $taxIncluded=(int)$taxLines->where('price_includes_tax',true)->sum('tax_minor');
                $taxAdded=(int)$taxLines->where('price_includes_tax',false)->sum('tax_minor');
                $platformTax=(int)$taxLines->where('liability_bearer','platform')->sum('tax_minor');
                $sellerTax=(int)$taxLines->where('liability_bearer','seller')->sum('tax_minor');
                $productIncludedTax=(int)$taxLines->where('source','product')->where('price_includes_tax',true)->sum('tax_minor');
                $shippingIncludedTax=(int)$taxLines->where('source','shipping')->where('price_includes_tax',true)->sum('tax_minor');
                $vendorTotal = max(0, $subtotal + $shipping - $discount + $taxAdded);
                $commissionBps = (int) ($vendor?->commission_bps ?? 0);
                $commissionBase = max(0, $subtotal - $sellerDiscount - $productIncludedTax);
                $commission = intdiv($commissionBase * $commissionBps, 10_000);
                $sellerPayable = max(0, $commissionBase + max(0,$shipping-$shippingIncludedTax) - $commission + $sellerTax);

                $vendorOrder = $order->vendorOrders()->create([
                    'public_id' => (string) Str::ulid(),
                    'vendor_id' => $vendorId,
                    'status' => OrderStatus::Confirmed,
                    'currency' => $session->currency,
                    'subtotal_minor' => $subtotal,
                    'shipping_minor' => $shipping,
                    'discount_minor' => $discount,
                    'seller_discount_minor' => $sellerDiscount,
                    'coupon_subsidy_minor' => $platformDiscount,
                    'tax_minor'=>$taxMinor,'tax_included_minor'=>$taxIncluded,'tax_added_minor'=>$taxAdded,'platform_tax_minor'=>$platformTax,'seller_tax_minor'=>$sellerTax,
                    'total_minor' => $vendorTotal,
                    'commission_bps' => $commissionBps,
                    'platform_commission_minor' => $commission,
                    'seller_payable_minor' => $sellerPayable,
                ]);

                foreach ($items as $sessionItem) {
                    $itemTaxLines=$session->taxLines->where('checkout_session_item_id',$sessionItem->id);
                    $itemTax=(int)$itemTaxLines->sum('tax_minor');$itemIncluded=(int)$itemTaxLines->where('price_includes_tax',true)->sum('tax_minor');$itemAdded=(int)$itemTaxLines->where('price_includes_tax',false)->sum('tax_minor');$itemPlatform=(int)$itemTaxLines->where('liability_bearer','platform')->sum('tax_minor');$itemSeller=(int)$itemTaxLines->where('liability_bearer','seller')->sum('tax_minor');$itemTaxable=(int)$itemTaxLines->max('taxable_minor');
                    $orderItem = $order->items()->create([
                        'vendor_order_id' => $vendorOrder->id,
                        'checkout_session_item_id'=>$sessionItem->id,
                        'product_id' => $sessionItem->product_id,
                        'product_variant_id' => $sessionItem->product_variant_id,
                        'product_name' => $sessionItem->product_name,
                        'variant_name' => $sessionItem->variant_name,
                        'sku' => $sessionItem->sku,
                        'quantity' => $sessionItem->quantity,
                        'currency' => $sessionItem->currency,
                        'unit_price_minor' => $sessionItem->unit_price_minor,
                        'line_total_minor' => $sessionItem->line_total_minor,
                        'taxable_minor'=>$itemTaxable,'tax_minor'=>$itemTax,'tax_included_minor'=>$itemIncluded,'tax_added_minor'=>$itemAdded,'platform_tax_minor'=>$itemPlatform,'seller_tax_minor'=>$itemSeller,
                        'selected_options' => $sessionItem->selected_options,
                        'metadata' => [
                            'discount_minor' => (int) $session->promotionAllocations->where('checkout_session_item_id',$sessionItem->id)->sum('discount_minor'),
                            'platform_discount_minor' => (int) $session->promotionAllocations->where('checkout_session_item_id',$sessionItem->id)->sum('platform_funded_minor'),
                            'seller_discount_minor' => (int) $session->promotionAllocations->where('checkout_session_item_id',$sessionItem->id)->sum('seller_funded_minor'),
                            'promotions' => $session->promotionAllocations->where('checkout_session_item_id',$sessionItem->id)->map(/** Inline callback for this operation. */ fn($a)=>['type'=>$a->source_type,'reference'=>$a->source_reference,'discountMinor'=>$a->discount_minor,'platformMinor'=>$a->platform_funded_minor,'sellerMinor'=>$a->seller_funded_minor])->values()->all(),
                        ],
                    ]);

                    $this->convertReservation->execute(
                        $sessionItem->reservation,
                        referenceType: 'order_item',
                        referenceId: (string) $orderItem->id,
                    );
                }
            }

            if (($session->metadata['coupon_type'] ?? null) === 'review_reward') {
                $this->redeemReviewCoupon->execute($user, $session, $order);
            }
            $this->redeemPromotions->execute($session, $order);
            $this->snapshotOrderTaxes->execute($order->fresh(['items','vendorOrders','checkoutSession.taxLines']));

            $session->update([
                'status' => CheckoutStatus::Converted,
                'converted_at' => now(),
            ]);
            $session->cart()->update(['status' => CartStatus::Converted]);

            return $this->load($order->fresh());
        }, 3);

        if (($session->metadata['coupon_type'] ?? null) === 'review_reward') {
            $this->redeemReviewCoupon->execute($user, $session, $order);
        }
        $this->redeemPromotions->execute($session, $order);
        $this->snapshotOrderTaxes->execute($order);
        $this->issueTaxInvoicesSafely($order);
        $this->postOrderFinanceSafely($order);
        $this->finalizeGiftSafely($order);
        $this->accrueAffiliateSafely($order);
        return $order;
    }

    /** Handles issue tax invoices safely for the place order workflow. */
    private function issueTaxInvoicesSafely(Order $order): void
    { try { $this->issueOrderInvoices->execute($order); } catch (\Throwable $e) { Log::error('Tax invoice issuance failed; tax reconciliation can retry.', ['order_id'=>$order->public_id,'exception'=>$e->getMessage()]); } }

    /** Handles post order finance safely for the place order workflow. */
    private function postOrderFinanceSafely(Order $order): void
    {
        try { $this->postOrderFinance->execute($order); }
        catch (\Throwable $exception) {
            Log::error('Order finance posting failed; reconciliation scheduler will retry.', ['order_id'=>$order->public_id,'exception'=>$exception->getMessage()]);
        }
    }

    /** Handles finalize gift safely for the place order workflow. */
    private function finalizeGiftSafely(Order $order): void
    {
        try {
            $this->finalizeGiftOrder->execute($order);
        } catch (\Throwable $exception) {
            Log::error('Gift finalization failed; scheduler/retry can safely recover.', [
                'order_id' => $order->public_id,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /** Handles accrue affiliate safely for the place order workflow. */
    private function accrueAffiliateSafely(Order $order): void
    {
        if ($order->payment_status !== PaymentStatus::Paid || $order->affiliate_accrued_at) return;
        try {
            $this->accrueAffiliateCommissions->execute($order);
        } catch (\Throwable $exception) {
            Log::error('Affiliate commission accrual failed; scheduler will retry.', [
                'order_id' => $order->public_id,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Allocate an integer amount proportionally to keyed subtotals while preserving the exact total.
     */
    private function allocate(int $amount, Collection $weights): array
    {
        $keys = $weights->keys()->all();
        if ($amount <= 0 || $weights->sum() <= 0 || $keys === []) {
            return array_fill_keys($keys, 0);
        }

        $totalWeight = (int) $weights->sum();
        $result = [];
        $allocated = 0;
        foreach ($weights as $key => $weight) {
            $share = intdiv($amount * (int) $weight, $totalWeight);
            $result[(string) $key] = $share;
            $allocated += $share;
        }

        $remainder = $amount - $allocated;
        foreach ($keys as $key) {
            if ($remainder <= 0) break;
            $result[(string) $key]++;
            $remainder--;
        }

        return $result;
    }

    /** Handles load for the place order workflow. */
    private function load(Order $order): Order
    {
        return $order->load(['items', 'vendorOrders.vendor', 'vendorOrders.items', 'vendorOrders.shipments', 'shipments.vendor', 'shippingAddress', 'gift.sender', 'gift.recipient', 'gift.product.images', 'gift.variant']);
    }
}
