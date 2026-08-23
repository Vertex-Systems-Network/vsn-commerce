<?php

namespace App\Domain\Catalog\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Resources\ProductResource;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductView;
use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Http\Request;

/** Defines the PersonalizationService class and its project responsibilities. */
class PersonalizationService
{
    /** Handles recommendations for the personalization service workflow. */
    public function recommendations(?User $user, Request $request, int $limit = 12): array
    {
        $limit = min(24, max(1, $limit));
        $categoryWeights = [];
        $vendorWeights = [];
        $exclude = [];

        if ($user) {
            $views = ProductView::query()
                ->where('user_id', $user->id)
                ->with('product:id,category_id,vendor_id')
                ->latest('viewed_at')
                ->limit(50)
                ->get();
            foreach ($views as $i => $view) {
                $weight = max(1, 10 - intdiv($i, 5));
                if ($view->product) {
                    $categoryWeights[$view->product->category_id] = ($categoryWeights[$view->product->category_id] ?? 0) + $weight;
                    $vendorWeights[$view->product->vendor_id] = ($vendorWeights[$view->product->vendor_id] ?? 0) + intdiv($weight + 1, 2);
                    $exclude[$view->product_id] = true;
                }
            }

            $wishlist = WishlistItem::query()
                ->where('user_id', $user->id)
                ->with('product:id,category_id,vendor_id')
                ->latest()
                ->limit(30)
                ->get();
            foreach ($wishlist as $item) {
                if ($item->product) {
                    $categoryWeights[$item->product->category_id] = ($categoryWeights[$item->product->category_id] ?? 0) + 12;
                    $vendorWeights[$item->product->vendor_id] = ($vendorWeights[$item->product->vendor_id] ?? 0) + 6;
                }
            }

            $bought = OrderItem::query()
                ->whereHas('order', fn ($query) => $query
                    ->where('user_id', $user->id)
                    ->whereIn('payment_status', [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value])
                    ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Refunded->value]))
                ->with('product:id,category_id,vendor_id')
                ->latest('id')
                ->limit(60)
                ->get();
            foreach ($bought as $item) {
                if ($item->product) {
                    $categoryWeights[$item->product->category_id] = ($categoryWeights[$item->product->category_id] ?? 0) + 8;
                    $vendorWeights[$item->product->vendor_id] = ($vendorWeights[$item->product->vendor_id] ?? 0) + 4;
                }
            }
        }

        $candidates = Product::query()
            ->published()
            ->with(['vendor', 'category', 'taxClass', 'images.mediaAsset', 'variants.inventories'])
            ->whereHas('variants.inventories', fn ($query) => $query->whereRaw('on_hand > (reserved + safety_stock)'))
            ->orderByDesc('sold_count')
            ->orderByDesc('rating')
            ->limit(160)
            ->get();

        $ranked = $candidates
            ->map(function (Product $product) use ($categoryWeights, $vendorWeights, $exclude, $user) {
                $score = (int) $product->sold_count + ((float) $product->rating * 10);
                $reason = 'Popular in the marketplace';
                if ($user) {
                    $categoryWeight = $categoryWeights[$product->category_id] ?? 0;
                    $vendorWeight = $vendorWeights[$product->vendor_id] ?? 0;
                    $score += ($categoryWeight * 100) + ($vendorWeight * 40) - (isset($exclude[$product->id]) ? 40 : 0);
                    if ($categoryWeight > 0) {
                        $reason = 'Because you explored '.$product->category?->name;
                    } elseif ($vendorWeight > 0) {
                        $reason = 'More from stores you engage with';
                    }
                }

                return ['product' => $product, 'score' => $score, 'reason' => $reason];
            })
            ->sortByDesc('score')
            ->take($limit)
            ->values();

        return $ranked
            ->map(fn ($row) => [
                'product' => (new ProductResource($row['product']))->resolve($request),
                'reason' => $row['reason'],
            ])
            ->all();
    }

    /** Handles recent for the personalization service workflow. */
    public function recent(User $user, Request $request, int $limit = 12): array
    {
        $rows = ProductView::query()
            ->where('user_id', $user->id)
            ->with([
                'product.vendor',
                'product.category',
                'product.taxClass',
                'product.images.mediaAsset',
                'product.variants.inventories',
            ])
            ->latest('viewed_at')
            ->limit(100)
            ->get()
            ->unique('product_id')
            ->filter(fn ($view) => $view->product?->status?->value === 'published')
            ->take(min(30, max(1, $limit)));

        return $rows
            ->map(fn ($view) => [
                'viewedAt' => $view->viewed_at?->toIso8601String(),
                'product' => (new ProductResource($view->product))->resolve($request),
            ])
            ->values()
            ->all();
    }

    /** Handles buy again for the personalization service workflow. */
    public function buyAgain(User $user, Request $request, int $limit = 12): array
    {
        $items = OrderItem::query()
            ->whereHas('order', fn ($query) => $query
                ->where('user_id', $user->id)
                ->whereIn('payment_status', [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value])
                ->whereIn('status', [OrderStatus::Delivered->value, OrderStatus::PartiallyRefunded->value]))
            ->whereColumn('refunded_quantity', '<', 'quantity')
            ->with([
                'order',
                'product.vendor',
                'product.category',
                'product.taxClass',
                'product.images.mediaAsset',
                'product.variants.inventories',
                'variant.inventories',
            ])
            ->latest('id')
            ->limit(150)
            ->get()
            ->unique(fn ($item) => $item->product_id.':'.($item->product_variant_id ?? 0))
            ->filter(fn ($item) => $item->product?->status?->value === 'published')
            ->take(min(30, max(1, $limit)));

        return $items
            ->map(function ($item) use ($request) {
                $variant = $item->variant;
                $stock = $variant?->inventories?->sum(fn ($inventory) => $inventory->available()) ?? 0;

                return [
                    'lastPurchasedAt' => $item->order?->placed_at?->toIso8601String(),
                    'previousUnitPriceMinor' => $item->unit_price_minor,
                    'variantId' => $variant?->id,
                    'variantName' => $item->variant_name,
                    'available' => (bool) ($variant?->is_active && $stock > 0),
                    'product' => (new ProductResource($item->product))->resolve($request),
                ];
            })
            ->values()
            ->all();
    }
}
