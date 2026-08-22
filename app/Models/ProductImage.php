<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the ProductImage class and its project responsibilities. */
class ProductImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'media_asset_id',
        'url',
        'source',
        'alt_text',
        'sort_order',
    ];

    /** Handles product for the product image workflow. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Handles media asset for the product image workflow. */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(ProductMediaAsset::class, 'media_asset_id');
    }

    /** Handles public url for the product image workflow. */
    public function publicUrl(): string
    {
        if ($this->source === 'managed' && $this->relationLoaded('mediaAsset') && $this->mediaAsset) {
            return \Illuminate\Support\Facades\Storage::disk($this->mediaAsset->disk)->url($this->mediaAsset->path);
        }
        return (string) $this->url;
    }
}
