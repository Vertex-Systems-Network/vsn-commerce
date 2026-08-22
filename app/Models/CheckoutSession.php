<?php

namespace App\Models;

use App\Enums\CheckoutStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Defines the CheckoutSession class and its project responsibilities. */
class CheckoutSession extends Model
{
    use HasFactory;

    protected $hidden = ['mysql_reserved_cart_guard'];

    protected $fillable = [
        'public_id', 'user_id', 'cart_id', 'idempotency_key', 'status', 'currency',
        'address_id', 'address_snapshot', 'shipping_method', 'payment_method', 'saved_payment_method_id', 'coupon_code',
        'subtotal_minor', 'shipping_minor', 'discount_minor', 'platform_discount_minor', 'seller_discount_minor', 'tax_minor', 'tax_included_minor', 'tax_added_minor', 'coin_redemption_coins', 'coin_redemption_minor', 'wallet_hold_id',
        'total_minor', 'expires_at', 'converted_at', 'cancelled_at', 'metadata',
    ];

    /** Handles casts for the checkout session workflow. */
    protected function casts(): array
    {
        return [
            'status' => CheckoutStatus::class,
            'address_snapshot' => 'array',
            'subtotal_minor' => 'integer',
            'shipping_minor' => 'integer',
            'discount_minor' => 'integer',
            'platform_discount_minor' => 'integer',
            'seller_discount_minor' => 'integer',
            'tax_minor' => 'integer', 'tax_included_minor'=>'integer', 'tax_added_minor'=>'integer',
            'coin_redemption_coins' => 'integer',
            'coin_redemption_minor' => 'integer',
            'total_minor' => 'integer',
            'expires_at' => 'datetime',
            'converted_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** Returns route key name. */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** Handles user for the checkout session workflow. */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    /** Handles cart for the checkout session workflow. */
    public function cart(): BelongsTo { return $this->belongsTo(Cart::class); }
    /** Handles address for the checkout session workflow. */
    public function address(): BelongsTo { return $this->belongsTo(Address::class); }
    /** Handles items for the checkout session workflow. */
    public function items(): HasMany { return $this->hasMany(CheckoutSessionItem::class); }
    /** Handles order for the checkout session workflow. */
    public function order(): HasOne { return $this->hasOne(Order::class); }
    /** Handles payment intents for the checkout session workflow. */
    public function paymentIntents(): HasMany { return $this->hasMany(PaymentIntent::class); }
    /** Handles saved payment method for the checkout session workflow. */
    public function savedPaymentMethod(): BelongsTo { return $this->belongsTo(SavedPaymentMethod::class); }
    /** Handles wallet hold for the checkout session workflow. */
    public function walletHold(): BelongsTo { return $this->belongsTo(WalletHold::class); }
    /** Handles gift for the checkout session workflow. */
    public function gift(): HasOne { return $this->hasOne(Gift::class); }
    /** Handles review coupon for the checkout session workflow. */
    public function reviewCoupon(): HasOne { return $this->hasOne(ReviewRewardCoupon::class, 'reserved_checkout_session_id'); }
    /** Handles promotion usages for the checkout session workflow. */
    public function promotionUsages(): HasMany { return $this->hasMany(PromotionUsage::class); }
    /** Handles promotion allocations for the checkout session workflow. */
    public function promotionAllocations(): HasMany { return $this->hasMany(CheckoutPromotionAllocation::class); }
    /** Handles tax lines for the checkout session workflow. */
    public function taxLines(): HasMany { return $this->hasMany(CheckoutTaxLine::class); }
}
