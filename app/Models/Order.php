<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Defines the Order class and its project responsibilities. */
class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id', 'user_id', 'checkout_session_id', 'status', 'payment_status', 'payment_method',
        'currency', 'subtotal_minor', 'shipping_minor', 'discount_minor', 'platform_discount_minor', 'seller_discount_minor', 'tax_minor', 'tax_included_minor', 'tax_added_minor', 'tax_refunded_minor', 'coin_redemption_coins', 'coin_redemption_minor', 'wallet_transaction_id',
        'total_minor', 'refunded_minor', 'cash_refunded_minor', 'coin_refunded_coins', 'placed_at', 'delivered_at', 'affiliate_accrued_at', 'metadata',
    ];

    /** Handles casts for the order workflow. */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'subtotal_minor' => 'integer', 'shipping_minor' => 'integer', 'discount_minor' => 'integer', 'platform_discount_minor'=>'integer', 'seller_discount_minor'=>'integer', 'tax_minor'=>'integer','tax_included_minor'=>'integer','tax_added_minor'=>'integer','tax_refunded_minor'=>'integer',
            'coin_redemption_coins' => 'integer', 'coin_redemption_minor' => 'integer', 'total_minor' => 'integer', 'refunded_minor' => 'integer', 'cash_refunded_minor' => 'integer', 'coin_refunded_coins' => 'integer', 'placed_at' => 'datetime', 'delivered_at' => 'datetime', 'affiliate_accrued_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** Returns route key name. */
    public function getRouteKeyName(): string { return 'public_id'; }
    /** Handles user for the order workflow. */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    /** Handles checkout session for the order workflow. */
    public function checkoutSession(): BelongsTo { return $this->belongsTo(CheckoutSession::class); }
    /** Handles items for the order workflow. */
    public function items(): HasMany { return $this->hasMany(OrderItem::class); }
    /** Handles vendor orders for the order workflow. */
    public function vendorOrders(): HasMany { return $this->hasMany(VendorOrder::class); }
    /** Handles shipping address for the order workflow. */
    public function shippingAddress(): HasOne { return $this->hasOne(OrderAddress::class)->where('type', 'shipping'); }
    /** Handles payment intents for the order workflow. */
    public function paymentIntents(): HasMany { return $this->hasMany(PaymentIntent::class); }
    /** Handles payment transactions for the order workflow. */
    public function paymentTransactions(): HasMany { return $this->hasMany(PaymentTransaction::class); }
    /** Handles affiliate commissions for the order workflow. */
    public function affiliateCommissions(): HasMany { return $this->hasMany(AffiliateCommission::class); }
    /** Handles return requests for the order workflow. */
    public function returnRequests(): HasMany { return $this->hasMany(ReturnRequest::class); }
    /** Handles refunds for the order workflow. */
    public function refunds(): HasMany { return $this->hasMany(Refund::class); }
    /** Handles gift for the order workflow. */
    public function gift(): HasOne { return $this->hasOne(Gift::class); }
    /** Handles reviews for the order workflow. */
    public function reviews(): HasMany { return $this->hasMany(Review::class); }
    /** Handles shipments for the order workflow. */
    public function shipments(): HasMany { return $this->hasMany(Shipment::class); }
    /** Handles tax lines for the order workflow. */
    public function taxLines(): HasMany { return $this->hasMany(OrderTaxLine::class); }
    /** Handles tax invoices for the order workflow. */
    public function taxInvoices(): HasMany { return $this->hasMany(TaxInvoice::class); }
}
