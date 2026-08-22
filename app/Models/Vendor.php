<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Defines the Vendor class and its project responsibilities. */
class Vendor extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_user_id',
        'name',
        'slug',
        'status',
        'commission_bps',
        'metadata',
    ];

    /** Handles casts for the vendor workflow. */
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /** Handles owner for the vendor workflow. */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** Handles products for the vendor workflow. */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** Handles vendor orders for the vendor workflow. */
    public function vendorOrders(): HasMany
    {
        return $this->hasMany(VendorOrder::class);
    }

    /** Updates tlements. */
    public function settlements(): HasMany
    {
        return $this->hasMany(VendorSettlement::class);
    }

    /** Handles payouts for the vendor workflow. */
    public function payouts(): HasMany
    {
        return $this->hasMany(VendorPayout::class);
    }

    /** Handles payout methods for the vendor workflow. */
    public function payoutMethods(): HasMany
    {
        return $this->hasMany(VendorPayoutMethod::class);
    }

    /** Handles tax profile for the vendor workflow. */
    public function taxProfile(): \Illuminate\Database\Eloquent\Relations\HasOne { return $this->hasOne(VendorTaxProfile::class); }

    /** Handles shipments for the vendor workflow. */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /** Handles risk profile for the vendor workflow. */
    public function riskProfile(): HasOne { return $this->hasOne(RiskProfile::class); }
    /** Handles risk events for the vendor workflow. */
    public function riskEvents(): HasMany { return $this->hasMany(RiskEvent::class); }
    /** Handles risk cases for the vendor workflow. */
    public function riskCases(): HasMany { return $this->hasMany(RiskCase::class); }
    /** Handles risk holds for the vendor workflow. */
    public function riskHolds(): HasMany { return $this->hasMany(RiskHold::class); }
}
