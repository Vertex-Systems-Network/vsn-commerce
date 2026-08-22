<?php

namespace App\Domain\Reviews\Actions;

use App\Enums\ReviewStatus;
use App\Models\Product;
use App\Models\Review;

/** Defines the RecalculateProductReviewAggregate class and its project responsibilities. */
class RecalculateProductReviewAggregate
{
    /** Executes the recalculate product review aggregate operation. */
    public function execute(?int $productId): void
    {
        if (! $productId) return;
        $product = Product::query()->find($productId);
        if (! $product) return;
        $query = Review::query()->where('product_id', $productId)->where('status', ReviewStatus::Approved->value);
        $count = (int) $query->count();
        $average = $count > 0 ? round((float) $query->avg('rating'), 2) : 0.0;
        $product->update(['rating'=>$average, 'reviews_count'=>$count]);
    }
}
