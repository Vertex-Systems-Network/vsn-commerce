<?php

namespace App\Domain\Checkout\Actions;

use App\Domain\Cart\Services\CartLoader;
use App\Domain\Checkout\Exceptions\CheckoutValidationException;
use App\Domain\Checkout\Services\CoinRedemptionResolver;
use App\Domain\Checkout\Services\CouponDiscountResolver;
use App\Domain\Checkout\Services\ShippingQuoteService;
use App\Domain\Inventory\Actions\ReserveInventory;
use App\Domain\Promotions\Actions\ApplyCheckoutPromotions;
use App\Domain\Risk\Exceptions\RiskBlockedException;
use App\Domain\Risk\Services\RiskGate;
use App\Domain\Tax\Services\CheckoutTaxCalculator;
use App\Domain\Wallet\Actions\CreateWalletHold;
use App\Domain\Wallet\Exceptions\WalletException;
use App\Enums\CartStatus;
use App\Enums\CheckoutStatus;
use App\Enums\ProductStatus;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CheckoutSession;
use App\Models\SavedPaymentMethod;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Defines the CreateCheckoutSession class and its project responsibilities. */
class CreateCheckoutSession
{
    /** Initializes the CreateCheckoutSession instance and its dependencies. */
    public function __construct(
        private readonly ReserveInventory $reserveInventory,
        private readonly ReleaseCheckoutSession $releaseCheckoutSession,
        private readonly ShippingQuoteService $shippingQuotes,
        private readonly CoinRedemptionResolver $coinResolver,
        private readonly CouponDiscountResolver $reviewCoupons,
        private readonly CreateWalletHold $createWalletHold,
        private readonly ApplyCheckoutPromotions $promotions,
        private readonly CheckoutTaxCalculator $taxes,
        private readonly RiskGate $risk,
    ) {}

    /** Executes the create checkout session operation. */
    public function execute(
        User $user,
        Cart $cart,
        Address $address,
        string $shippingMethod,
        string $paymentMethod,
        string $idempotencyKey,
        ?string $couponCode = null,
        int $coinRedemptionCoins = 0,
        ?string $savedPaymentMethodId = null,
    ): CheckoutSession {
        $existing = CheckoutSession::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            if ($existing->user_id !== $user->id || $existing->cart_id !== $cart->id) {
                throw new CheckoutValidationException('Idempotency key has already been used for a different checkout.', 'idempotencyKey');
            }

            return $this->load($existing);
        }
        if ($address->user_id !== $user->id) {
            throw new CheckoutValidationException('The selected address does not belong to this account.', 'addressId');
        }
        try {
            $this->risk->assertAllowed($user, 'payments');
        } catch (RiskBlockedException $e) {
            throw new CheckoutValidationException($e->getMessage(), 'risk');
        }

        $paymentConfig = config("vsn.payments.methods.{$paymentMethod}");
        if (! is_array($paymentConfig) || ! (bool) ($paymentConfig['enabled'] ?? false)) {
            throw new CheckoutValidationException('The selected payment method is not enabled.', 'paymentMethod');
        }
        $savedPaymentMethod = null;
        if ($savedPaymentMethodId !== null) {
            if ($paymentMethod !== 'card') {
                throw new CheckoutValidationException('A saved payment method can only be used with card payment.', 'savedPaymentMethodId');
            }
            $savedPaymentMethod = SavedPaymentMethod::query()->where('public_id', $savedPaymentMethodId)->where('user_id', $user->id)->where('status', 'active')->first();
            if (! $savedPaymentMethod || ! $savedPaymentMethod->isActive()) {
                throw new CheckoutValidationException('The selected saved payment method is unavailable.', 'savedPaymentMethodId');
            }
            if (($paymentConfig['provider'] ?? null) !== $savedPaymentMethod->provider) {
                throw new CheckoutValidationException('The saved payment method belongs to a different payment provider.', 'savedPaymentMethodId');
            }
        }

        // Terminal review-coupon lifecycle changes must survive a rejected checkout transaction.
        // A valid/reserved coupon is still revalidated under lock after prior-session release below.
        $this->reviewCoupons->preflight($user, $couponCode);

        try {
            return DB::transaction(/** Inline callback for this operation. */ function () use ($user, $cart, $address, $shippingMethod, $paymentMethod, $idempotencyKey, $couponCode, $coinRedemptionCoins, $savedPaymentMethod): CheckoutSession {
                $cart = Cart::query()->whereKey($cart->id)->where('user_id', $user->id)->where('status', CartStatus::Active->value)->lockForUpdate()->firstOrFail();
                $existing = CheckoutSession::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    if ($existing->user_id !== $user->id || $existing->cart_id !== $cart->id) {
                        throw new CheckoutValidationException('Idempotency key has already been used for a different checkout.', 'idempotencyKey');
                    }

                    return $this->load($existing);
                }

                $cart->load(CartLoader::relations());
                if ($cart->items->isEmpty()) {
                    throw new CheckoutValidationException('Your cart is empty.', 'cart');
                }
                $priorSessions = CheckoutSession::query()->where('cart_id', $cart->id)->where('status', CheckoutStatus::Reserved->value)->lockForUpdate()->get();
                foreach ($priorSessions as $prior) {
                    $this->releaseCheckoutSession->execute($prior);
                }
                $cart->load(CartLoader::relations());

                $currencies = $cart->items->pluck('currency')->unique();
                if ($currencies->count() !== 1 || $currencies->first() !== $cart->currency) {
                    throw new CheckoutValidationException('Cart currency is inconsistent.', 'cart');
                }
                foreach ($cart->items as $item) {
                    $product = $item->product;
                    $variant = $item->variant;
                    $available = $item->availableStock();
                    if ($product?->status !== ProductStatus::Published || ! $variant?->is_active) {
                        throw new CheckoutValidationException("{$product?->name} is no longer purchasable.", 'cart');
                    }
                    if ($available < $item->quantity) {
                        throw new CheckoutValidationException("Only {$available} unit(s) of {$product->name} are currently available.", 'cart');
                    }
                }

                $quote = $this->shippingQuotes->resolve($cart, $address, $shippingMethod);
                $subtotal = (int) $cart->items->sum(/** Inline callback for this operation. */ fn ($item) => $item->currentUnitPriceMinor() * $item->quantity);
                $session = CheckoutSession::create([
                    'public_id' => (string) Str::ulid(),
                    'user_id' => $user->id,
                    'cart_id' => $cart->id,
                    'idempotency_key' => $idempotencyKey,
                    'status' => CheckoutStatus::Reserved,
                    'currency' => $cart->currency,
                    'address_id' => $address->id,
                    'address_snapshot' => $address->only(['label', 'recipient_name', 'phone', 'line1', 'line2', 'city', 'state', 'postal_code', 'country_code']),
                    'shipping_method' => $shippingMethod,
                    'payment_method' => $paymentMethod,
                    'saved_payment_method_id' => $savedPaymentMethod?->id,
                    'coupon_code' => $couponCode ?: null,
                    'subtotal_minor' => $subtotal,
                    'shipping_minor' => $quote['amountMinor'],
                    'discount_minor' => 0,
                    'platform_discount_minor' => 0,
                    'seller_discount_minor' => 0,
                    'tax_minor' => 0,
                    'tax_included_minor' => 0,
                    'tax_added_minor' => 0,
                    'coin_redemption_coins' => 0,
                    'coin_redemption_minor' => 0,
                    'total_minor' => $subtotal + (int) $quote['amountMinor'],
                    'expires_at' => now()->addMinutes(config('vsn.inventory_reservation_minutes', 15)),
                    'metadata' => ['shipping_quote' => $quote, 'pricing_version' => 2],
                ]);

                foreach ($cart->items as $cartItem) {
                    $unitPrice = $cartItem->currentUnitPriceMinor();
                    $sessionItem = $session->items()->create([
                        'cart_item_id' => $cartItem->id,
                        'product_id' => $cartItem->product_id,
                        'product_variant_id' => $cartItem->product_variant_id,
                        'vendor_id' => $cartItem->product?->vendor_id,
                        'product_name' => $cartItem->product?->name ?? 'Product',
                        'variant_name' => $cartItem->variant?->name ?? 'Default',
                        'sku' => $cartItem->variant?->sku,
                        'quantity' => $cartItem->quantity,
                        'currency' => $cartItem->currency,
                        'unit_price_minor' => $unitPrice,
                        'line_total_minor' => $unitPrice * $cartItem->quantity,
                        'selected_options' => $cartItem->selected_options,
                    ]);
                    $reservation = $this->reserveInventory->execute(
                        user: $user,
                        variantId: $cartItem->product_variant_id,
                        quantity: $cartItem->quantity,
                        idempotencyKey: "checkout:{$session->public_id}:item:{$cartItem->id}",
                        reference: "checkout:{$session->public_id}",
                    );
                    $sessionItem->update(['inventory_reservation_id' => $reservation->id]);
                }

                $session->load(['items.product.category', 'items.vendor']);
                $promotionResult = $this->promotions->execute($user, $session, $couponCode);
                $discount = (int) $promotionResult['discountMinor'];
                $taxResult = $this->taxes->execute($session->fresh(['items.product', 'items.vendor', 'promotionAllocations']));
                $beforeCoins = max(0, $subtotal + (int) $quote['amountMinor'] - $discount + (int) $taxResult['taxAddedMinor']);
                $redemption = $this->coinResolver->resolve($user, $coinRedemptionCoins, $beforeCoins);
                $coins = (int) $redemption['coins'];
                $coinValueMinor = (int) $redemption['amountMinor'];
                $total = max(0, $beforeCoins - $coinValueMinor);
                if ($paymentMethod === 'coins' && ($coins <= 0 || $total !== 0)) {
                    throw new CheckoutValidationException('VSN Coins payment must cover the full payable total. Use COD/card for split payment.', 'paymentMethod');
                }
                if ($coins > 0 && $total === 0 && $paymentMethod !== 'coins') {
                    throw new CheckoutValidationException('Select VSN Coins as the payment method when coins cover the full checkout total.', 'paymentMethod');
                }

                $metadata = $session->metadata ?? [];
                $metadata['coupon_type'] = $promotionResult['reviewCoupon'] ? 'review_reward' : ($couponCode ? 'promotion_code' : null);
                $metadata['promotions'] = $promotionResult['sources'];
                $session->update([
                    'discount_minor' => $discount,
                    'platform_discount_minor' => $promotionResult['platformMinor'],
                    'seller_discount_minor' => $promotionResult['sellerMinor'],
                    'tax_minor' => $taxResult['taxMinor'],
                    'tax_included_minor' => $taxResult['taxIncludedMinor'],
                    'tax_added_minor' => $taxResult['taxAddedMinor'],
                    'coin_redemption_coins' => $coins,
                    'coin_redemption_minor' => $coinValueMinor,
                    'total_minor' => $total,
                    'metadata' => $metadata,
                ]);

                if ($coins > 0) {
                    try {
                        $hold = $this->createWalletHold->execute(
                            user: $user,
                            coins: $coins,
                            idempotencyKey: "checkout-coins:{$session->public_id}",
                            referenceType: 'checkout_session',
                            referenceId: $session->public_id,
                            expiresAt: $session->expires_at,
                        );
                    } catch (WalletException $exception) {
                        throw new CheckoutValidationException($exception->getMessage(), 'coinRedemptionCoins');
                    }
                    $session->update(['wallet_hold_id' => $hold->id]);
                }

                return $this->load($session->fresh());
            }, 3);
        } catch (QueryException $exception) {
            $winner = CheckoutSession::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($winner && $winner->user_id === $user->id && $winner->cart_id === $cart->id) {
                return $this->load($winner);
            }

            throw $exception;
        }
    }

    /** Handles load for the create checkout session workflow. */
    private function load(CheckoutSession $session): CheckoutSession
    {
        return $session->load(['items.reservation.inventory', 'items.vendor', 'address', 'order', 'savedPaymentMethod', 'promotionAllocations.promotion', 'promotionUsages.promotion']);
    }
}
