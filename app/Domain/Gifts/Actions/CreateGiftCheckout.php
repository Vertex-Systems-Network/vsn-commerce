<?php

namespace App\Domain\Gifts\Actions;

use App\Domain\Cart\Exceptions\CartValidationException;
use App\Domain\Cart\Services\PurchasableVariantResolver;
use App\Domain\Checkout\Exceptions\CheckoutValidationException;
use App\Domain\Checkout\Services\CoinRedemptionResolver;
use App\Domain\Checkout\Services\ShippingQuoteService;
use App\Domain\Gifts\Exceptions\GiftException;
use App\Domain\Gifts\Services\GiftRecipientResolver;
use App\Domain\Inventory\Actions\ReserveInventory;
use App\Domain\Promotions\Actions\ApplyCheckoutPromotions;
use App\Domain\Inventory\Exceptions\InsufficientInventory;
use App\Domain\Wallet\Actions\CreateWalletHold;
use App\Domain\Wallet\Exceptions\WalletException;
use App\Enums\CartStatus;
use App\Enums\CheckoutStatus;
use App\Enums\GiftStatus;
use App\Enums\GiftRewardStatus;
use App\Models\Cart;
use App\Models\CheckoutSession;
use App\Models\Gift;
use App\Models\GiftSenderReward;
use App\Models\SavedPaymentMethod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Defines the CreateGiftCheckout class and its project responsibilities. */
class CreateGiftCheckout
{
    /** Initializes the CreateGiftCheckout instance and its dependencies. */
    public function __construct(
        private readonly GiftRecipientResolver $recipients,
        private readonly PurchasableVariantResolver $variants,
        private readonly ShippingQuoteService $shipping,
        private readonly CoinRedemptionResolver $coins,
        private readonly CreateWalletHold $createWalletHold,
        private readonly ReserveInventory $reserveInventory,
        private readonly ApplyCheckoutPromotions $promotions,
    ) {}

    /** @return array{gift:Gift,checkout:CheckoutSession} */
    public function execute(User $sender, array $data): array
    {
        $existing = Gift::query()->where('idempotency_key', $data['idempotencyKey'])->first();
        if ($existing) {
            if ($existing->sender_user_id !== $sender->id) throw new GiftException('Idempotency key has already been used.', 'idempotencyKey');
            return ['gift'=>$this->loadGift($existing), 'checkout'=>$this->loadCheckout($existing->checkoutSession)];
        }

        $recipient = $this->recipients->resolve($sender, $data['recipient']);
        $address = $recipient->addresses()->orderByDesc('is_default')->oldest('id')->first();
        if (! $address) throw new GiftException('Recipient cannot receive product gifts until a delivery address is saved.', 'recipient');

        $variant = $this->variants->resolve(
            variantId: $data['variantId'] ?? null,
            productId: $data['productId'] ?? null,
            selectedVariant: $data['selectedVariant'] ?? null,
            productSlug: $data['productSlug'] ?? null,
            selectedOptions: $data['selectedOptions'] ?? null,
        );
        if ($this->variants->available($variant) < 1) throw new GiftException('The selected product option is out of stock.', 'product');

        $paymentMethod = (string) $data['paymentMethod'];
        if ($paymentMethod === 'cod' && ! (bool) config('vsn.gifts.cod_enabled', false)) {
            throw new GiftException('Cash on delivery is disabled for recipient-paid gift deliveries. Use card or VSN Coins.', 'paymentMethod');
        }
        $paymentConfig = config("vsn.payments.methods.{$paymentMethod}");
        if (! is_array($paymentConfig) || ! (bool) ($paymentConfig['enabled'] ?? false)) throw new GiftException('The selected gift payment method is unavailable.', 'paymentMethod');
        $savedPaymentMethod = null;
        if (! empty($data['savedPaymentMethodId'])) {
            if ($paymentMethod !== 'card') throw new GiftException('A saved payment method can only be used with card payment.', 'savedPaymentMethodId');
            $savedPaymentMethod = SavedPaymentMethod::query()->where('public_id',$data['savedPaymentMethodId'])->where('user_id',$sender->id)->where('status','active')->first();
            if (! $savedPaymentMethod || ! $savedPaymentMethod->isActive()) throw new GiftException('The selected saved payment method is unavailable.', 'savedPaymentMethodId');
            if (($paymentConfig['provider'] ?? null) !== $savedPaymentMethod->provider) throw new GiftException('The saved payment method belongs to a different provider.', 'savedPaymentMethodId');
        }

        $scheduled = null;
        if (! empty($data['scheduledFor'])) {
            $scheduled = CarbonImmutable::parse($data['scheduledFor']);
            if ($scheduled->isPast()) throw new GiftException('Scheduled delivery must be in the future.', 'scheduledFor');
            if ($scheduled->greaterThan(now()->addDays((int) config('vsn.gifts.max_schedule_days', 90)))) throw new GiftException('Scheduled delivery is too far in the future.', 'scheduledFor');
        }

        return DB::transaction(/** Inline callback for this operation. */ function () use ($sender,$recipient,$address,$variant,$paymentMethod,$scheduled,$data,$savedPaymentMethod): array {
            $existing = Gift::query()->where('idempotency_key', $data['idempotencyKey'])->lockForUpdate()->first();
            if ($existing) return ['gift'=>$this->loadGift($existing), 'checkout'=>$this->loadCheckout($existing->checkoutSession)];

            $cart = Cart::create([
                'public_id'=>(string)Str::ulid(),'user_id'=>$sender->id,'status'=>CartStatus::Gift,'currency'=>$variant->product->currency,
                'metadata'=>['purpose'=>'gift','recipient_user_id'=>$recipient->id],
            ]);
            $price = $this->variants->priceMinor($variant);
            $cartItem = $cart->items()->create([
                'product_id'=>$variant->product_id,'product_variant_id'=>$variant->id,'quantity'=>1,'currency'=>$variant->product->currency,
                'unit_price_minor'=>$price,'compare_at_price_minor'=>$this->variants->compareAtPriceMinor($variant),'selected_options'=>$variant->option_values,
                'metadata'=>['purpose'=>'gift'],
            ]);
            $cart->load(['items.product.vendor','items.variant.inventories']);
            $quote = $this->shipping->resolve($cart, $address, $data['shippingMethod']);
            $sessionPublicId = (string) Str::ulid();
            $wrapListMinor = ! empty($data['giftWrap']) ? (int) config('vsn.gifts.gift_wrap_minor', 29_900) : 0;
            $wrapMinor = $wrapListMinor;
            $wrapReward = null;
            if ($wrapListMinor > 0) {
                $wrapReward = GiftSenderReward::query()
                    ->where('user_id', $sender->id)
                    ->where('reward_code', 'free_gift_wrap')
                    ->where('status', GiftRewardStatus::Available->value)
                    ->lockForUpdate()
                    ->first();
                if ($wrapReward) {
                    $rewardMetadata = $wrapReward->metadata ?? [];
                    $rewardMetadata['reserved_for_checkout'] = $sessionPublicId;
                    $rewardMetadata['reserved_at'] = now()->toIso8601String();
                    $wrapReward->update(['status'=>GiftRewardStatus::Reserved,'metadata'=>$rewardMetadata]);
                    $wrapMinor = 0;
                }
            }
            $subtotal = $price + $wrapMinor;
            $session = CheckoutSession::create([
                'public_id'=>$sessionPublicId,'user_id'=>$sender->id,'cart_id'=>$cart->id,'idempotency_key'=>'gift-checkout:'.hash('sha256',$data['idempotencyKey']),
                'status'=>CheckoutStatus::Reserved,'currency'=>$variant->product->currency,'address_id'=>$address->id,
                'address_snapshot'=>$address->only(['label','recipient_name','phone','line1','line2','city','state','postal_code','country_code']),
                'shipping_method'=>$data['shippingMethod'],'payment_method'=>$paymentMethod,'saved_payment_method_id'=>$savedPaymentMethod?->id,'subtotal_minor'=>$subtotal,'shipping_minor'=>$quote['amountMinor'],
                'discount_minor'=>0,'platform_discount_minor'=>0,'seller_discount_minor'=>0,'coin_redemption_coins'=>0,'coin_redemption_minor'=>0,'total_minor'=>$subtotal+(int)$quote['amountMinor'],
                'expires_at'=>now()->addMinutes(config('vsn.inventory_reservation_minutes',15)),
                'metadata'=>['purpose'=>'gift','shipping_quote'=>$quote,'pricing_version'=>2,'gift_wrap_minor'=>$wrapMinor,'gift_wrap_list_minor'=>$wrapListMinor,'gift_wrap_discount_minor'=>$wrapListMinor-$wrapMinor,'gift_wrap_reward_id'=>$wrapReward?->public_id,'recipient_user_id'=>$recipient->id,'scheduled_for'=>$scheduled?->toIso8601String()],
            ]);
            $sessionItem = $session->items()->create([
                'cart_item_id'=>$cartItem->id,'product_id'=>$variant->product_id,'product_variant_id'=>$variant->id,'vendor_id'=>$variant->product->vendor_id,
                'product_name'=>$variant->product->name,'variant_name'=>$variant->name,'sku'=>$variant->sku,'quantity'=>1,'currency'=>$variant->product->currency,
                'unit_price_minor'=>$price,'line_total_minor'=>$price,'selected_options'=>$variant->option_values,'metadata'=>['purpose'=>'gift'],
            ]);
            try {
                $reservation = $this->reserveInventory->execute(
                    user: $sender,
                    variantId: $variant->id,
                    quantity: 1,
                    idempotencyKey: "gift:{$session->public_id}:item:{$cartItem->id}",
                    reference: "gift:{$session->public_id}",
                );
            } catch (InsufficientInventory $e) {
                throw new GiftException('The selected product option is no longer available.', 'product');
            }
            $sessionItem->update(['inventory_reservation_id'=>$reservation->id]);
            $session->load(['items.product.category','items.vendor']);
            $promotionResult=$this->promotions->execute($sender,$session,null);
            $beforeCoins = $subtotal + (int) $quote['amountMinor'] - (int)$promotionResult['discountMinor'];
            $requestedCoins = (int) ($data['coinRedemptionCoins'] ?? 0);
            if ($paymentMethod === 'coins') {
                if ($beforeCoins % 100 !== 0) throw new GiftException('This gift total cannot currently be paid fully with VSN Coins.', 'paymentMethod');
                $requestedCoins = intdiv($beforeCoins, 100) * (int) config('vsn.coins_per_rupee', 70);
            }
            try { $redemption = $this->coins->resolve($sender, $requestedCoins, $beforeCoins); }
            catch (CheckoutValidationException $e) { throw new GiftException($e->getMessage(), $e->field); }
            $total = max(0, $beforeCoins - $redemption['amountMinor']);
            if ($paymentMethod === 'coins' && $total !== 0) throw new GiftException('VSN Coins do not fully fund this gift.', 'paymentMethod');
            $meta=$session->metadata??[];$meta['promotions']=$promotionResult['sources'];
            $session->update(['discount_minor'=>$promotionResult['discountMinor'],'platform_discount_minor'=>$promotionResult['platformMinor'],'seller_discount_minor'=>$promotionResult['sellerMinor'],'coin_redemption_coins'=>$redemption['coins'],'coin_redemption_minor'=>$redemption['amountMinor'],'total_minor'=>$total,'metadata'=>$meta]);
            if ($redemption['coins'] > 0) {
                try {
                    $hold = $this->createWalletHold->execute($sender,$redemption['coins'],"gift-checkout-coins:{$session->public_id}",'checkout_session',$session->public_id,$session->expires_at);
                } catch (WalletException $e) {
                    throw new GiftException($e->getMessage(), 'coinRedemptionCoins');
                }
                $session->update(['wallet_hold_id'=>$hold->id]);
            }
            $giftValueCoins = intdiv($price, 100) * (int) config('vsn.coins_per_rupee', 70);
            $gift = Gift::create([
                'public_id'=>(string)Str::ulid(),'sender_user_id'=>$sender->id,'recipient_user_id'=>$recipient->id,'checkout_session_id'=>$session->id,
                'product_id'=>$variant->product_id,'product_variant_id'=>$variant->id,'status'=>GiftStatus::AwaitingPayment,'currency'=>$variant->product->currency,
                'product_value_minor'=>$price,'gift_wrap_minor'=>$wrapMinor,'gift_value_minor'=>$beforeCoins,'gift_value_coins'=>$giftValueCoins,
                'message'=>trim((string)($data['message'] ?? '')) ?: null,'anonymous'=>(bool)($data['anonymous'] ?? false),'gift_wrap'=>(bool)($data['giftWrap'] ?? false),
                'scheduled_for'=>$scheduled,'idempotency_key'=>$data['idempotencyKey'],'metadata'=>['shipping_method'=>$data['shippingMethod'],'gift_wrap_list_minor'=>$wrapListMinor,'gift_wrap_discount_minor'=>$wrapListMinor-$wrapMinor,'gift_wrap_reward_id'=>$wrapReward?->public_id],
            ]);
            return ['gift'=>$this->loadGift($gift), 'checkout'=>$this->loadCheckout($session)];
        }, 3);
    }

    /** Handles load gift for the create gift checkout workflow. */
    private function loadGift(Gift $gift): Gift { return $gift->load(['sender','recipient','product.images','variant','order','checkoutSession.paymentIntents']); }
    /** Handles load checkout for the create gift checkout workflow. */
    private function loadCheckout(CheckoutSession $session): CheckoutSession { return $session->load(['items.reservation.inventory','items.vendor','walletHold','order','gift','savedPaymentMethod']); }
}
