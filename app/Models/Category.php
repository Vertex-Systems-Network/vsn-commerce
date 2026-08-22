<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Defines the Category class and its project responsibilities. */
class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'is_active',
        'sort_order',
    ];

    /** Handles casts for the category workflow. */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Handles parent for the category workflow. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Handles children for the category workflow. */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** Handles products for the category workflow. */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
