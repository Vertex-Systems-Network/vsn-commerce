<?php

namespace App\Models;

use App\Enums\ProductStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Defines the Product class and its project responsibilities. */
class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'public_id',
        'vendor_id',
        'category_id',
        'sku',
        'slug',
        'name',
        'short_description',
        'description',
        'status',
        'currency',
        'base_price_minor',
        'compare_at_price_minor',
        'rating',
        'reviews_count',
        'sold_count',
        'installment_enabled',
        'game_enabled',
        'tax_class_id', 'price_includes_tax',
        'metadata',
    ];

    /** Handles casts for the product workflow. */
    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'base_price_minor' => 'integer',
            'compare_at_price_minor' => 'integer',
            'rating' => 'decimal:2',
            'reviews_count' => 'integer',
            'sold_count' => 'integer',
            'installment_enabled' => 'boolean',
            'game_enabled' => 'boolean',
            'price_includes_tax' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /** Applies the published query scope. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ProductStatus::Published->value);
    }

    /** Handles resolve route binding for the product workflow. */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        if ($field) return parent::resolveRouteBinding($value, $field);
        return $this->newQuery()->where(/** Inline callback for this operation. */ function (Builder $query) use ($value): void {
            $query->where('public_id', (string) $value)->orWhere('slug', (string) $value);
            if (ctype_digit((string) $value)) $query->orWhere('id', (int) $value);
        })->first();
    }

    /** Handles tax class for the product workflow. */
    public function taxClass(): BelongsTo { return $this->belongsTo(TaxClass::class); }

    /** Handles vendor for the product workflow. */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** Handles category for the product workflow. */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** Handles variants for the product workflow. */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /** Handles images for the product workflow. */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    /** Handles cart items for the product workflow. */
    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /** Handles games for the product workflow. */
    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }

    /** Handles gifts for the product workflow. */
    public function gifts(): HasMany
    {
        return $this->hasMany(Gift::class);
    }

    /** Handles reviews for the product workflow. */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /** Handles alerts for the product workflow. */
    public function alerts(): HasMany
    {
        return $this->hasMany(ProductAlert::class);
    }

    /** Handles price history for the product workflow. */
    public function priceHistory(): HasMany
    {
        return $this->hasMany(ProductPriceHistory::class);
    }

    /** Handles wishlist items for the product workflow. */
    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    /** Handles views for the product workflow. */
    public function views(): HasMany
    {
        return $this->hasMany(ProductView::class);
    }

    /** Handles promotion scopes for the product workflow. */
    public function promotionScopes(): HasMany { return $this->hasMany(PromotionScope::class); }

    /** Handles media assets for the product workflow. */
    public function mediaAssets(): HasMany
    {
        return $this->hasMany(ProductMediaAsset::class)->orderBy('sort_order');
    }
}
