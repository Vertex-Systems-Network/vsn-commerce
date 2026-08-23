<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaxInvoice extends Model
{
    protected $fillable = [
        'public_id',
        'invoice_number',
        'order_id',
        'vendor_order_id',
        'vendor_id',
        'status',
        'currency',
        'seller_snapshot',
        'buyer_snapshot',
        'subtotal_minor',
        'discount_minor',
        'taxable_minor',
        'tax_minor',
        'total_minor',
        'issued_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'seller_snapshot' => 'array',
            'buyer_snapshot' => 'array',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'taxable_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'issued_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function vendorOrder(): BelongsTo
    {
        return $this->belongsTo(VendorOrder::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TaxInvoiceItem::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(TaxCreditNote::class);
    }

    protected static function booted(): void
    {
        static::updating(/** Inline callback for this operation. */ fn () => throw new \LogicException('TaxInvoice records are immutable.'));
        static::deleting(/** Inline callback for this operation. */ fn () => throw new \LogicException('TaxInvoice records are immutable.'));
    }
}
