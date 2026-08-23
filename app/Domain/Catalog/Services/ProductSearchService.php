<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Actions\RecordCatalogSearch;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Defines the ProductSearchService class and its project responsibilities. */
class ProductSearchService
{
    /** Initializes the ProductSearchService instance and its dependencies. */
    public function __construct(
        private readonly RecordCatalogSearch $recordSearch,
        private readonly CatalogCache $cache,
    ) {}

    /** Handles search for the product search service workflow. */
    public function search(Request $request): array
    {
        $perPage = min(60, max(1, (int) $request->input('perPage', 24)));
        $q = trim((string) $request->input('q', ''));
        $query = Product::query()
            ->published()
            ->with(['vendor', 'category', 'taxClass', 'images.mediaAsset', 'variants.inventories']);

        if ($q !== '') {
            $this->applyText($query, $q);
        }
        if ($category = $request->string('category')->toString()) {
            $slug = Str::slug($category);
            $query->whereHas('category', fn ($x) => $x->where('slug', $slug)->orWhere('name', $category));
        }
        if ($vendor = $request->string('vendor')->toString()) {
            $query->whereHas('vendor', fn ($x) => $x->where('slug', $vendor));
        }
        if ($request->filled('minRating')) {
            $query->where('rating', '>=', max(1, min(5, (float) $request->input('minRating'))));
        }
        if ($request->filled('minPriceMinor')) {
            $query->where('base_price_minor', '>=', (int) $request->input('minPriceMinor'));
        }
        if ($request->filled('maxPriceMinor')) {
            $query->where('base_price_minor', '<=', (int) $request->input('maxPriceMinor'));
        }
        if ($request->boolean('installment')) {
            $query->where('installment_enabled', true);
        }
        if ($request->boolean('game')) {
            $query->where('game_enabled', true);
        }
        if ($request->boolean('inStock')) {
            $query->whereHas('variants.inventories', fn ($x) => $x->whereRaw('on_hand > (reserved + safety_stock)'));
        }

        $this->sort($query, (string) $request->input('sort', $q !== '' ? 'relevance' : 'newest'), $q);
        $rows = $query->paginate($perPage);

        if ($q !== '') {
            $this->recordSearch->execute($request, $q, $rows->total(), array_filter([
                'category' => $request->input('category'),
                'vendor' => $request->input('vendor'),
                'minPriceMinor' => $request->input('minPriceMinor'),
                'maxPriceMinor' => $request->input('maxPriceMinor'),
                'minRating' => $request->input('minRating'),
                'inStock' => $request->boolean('inStock') ? true : null,
            ]));
        }

        return [
            'items' => ProductResource::collection($rows->getCollection())->resolve($request),
            'meta' => [
                'total' => $rows->total(),
                'currentPage' => $rows->currentPage(),
                'lastPage' => $rows->lastPage(),
                'perPage' => $rows->perPage(),
            ],
            'facets' => $this->facets($request, $q),
        ];
    }

    /** Handles apply text for the product search service workflow. */
    private function applyText(Builder $query, string $term): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $query->where(function (Builder $x) use ($term) {
                $x->whereRaw("search_vector @@ websearch_to_tsquery('simple', ?)", [$term])
                    ->orWhere('name', 'ilike', '%'.$term.'%')
                    ->orWhere('sku', 'ilike', '%'.$term.'%');
            });

            return;
        }

        $like = '%'.strtolower($term).'%';
        $query->where(function (Builder $x) use ($like) {
            $x->whereRaw('lower(name) like ?', [$like])
                ->orWhereRaw("lower(coalesce(sku,'')) like ?", [$like])
                ->orWhereRaw("lower(coalesce(short_description,'')) like ?", [$like]);
        });
    }

    /** Handles sort for the product search service workflow. */
    private function sort(Builder $query, string $sort, string $q): void
    {
        if ($sort === 'relevance' && $q !== '' && DB::getDriverName() === 'pgsql') {
            $query->orderByRaw("ts_rank(search_vector, websearch_to_tsquery('simple', ?)) DESC", [$q])
                ->orderByDesc('rating');

            return;
        }

        match ($sort) {
            'price_asc' => $query->orderBy('base_price_minor')->orderBy('id'),
            'price_desc' => $query->orderByDesc('base_price_minor')->orderByDesc('id'),
            'rating' => $query->orderByDesc('rating')->orderByDesc('reviews_count'),
            'popular' => $query->orderByDesc('sold_count')->orderByDesc('rating'),
            default => $query->latest('id'),
        };
    }

    /** Handles facets for the product search service workflow. */
    private function facets(Request $request, string $q): array
    {
        $base = Product::query()->published();
        if ($q !== '') {
            $this->applyText($base, $q);
        }

        $dimensions = $this->cache->remember(
            'search:facets:dimensions',
            (int) config('vsn.performance.catalog_cache_seconds', 120),
            function (): array {
                $categories = Category::query()
                    ->where('is_active', true)
                    ->withCount(['products' => fn ($x) => $x->published()])
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get()
                    ->map(fn ($c) => ['name' => $c->name, 'slug' => $c->slug, 'count' => $c->products_count])
                    ->values()
                    ->all();
                $vendors = Vendor::query()
                    ->where('status', 'active')
                    ->whereHas('products', fn ($x) => $x->published())
                    ->withCount(['products' => fn ($x) => $x->published()])
                    ->orderByDesc('products_count')
                    ->limit(30)
                    ->get()
                    ->map(fn ($v) => ['name' => $v->name, 'slug' => $v->slug, 'count' => $v->products_count])
                    ->all();

                return ['categories' => $categories, 'vendors' => $vendors];
            }
        );
        $prices = (clone $base)
            ->selectRaw('MIN(base_price_minor) as min_price, MAX(base_price_minor) as max_price')
            ->first();

        return $dimensions + [
            'price' => [
                'minMinor' => (int) ($prices?->min_price ?? 0),
                'maxMinor' => (int) ($prices?->max_price ?? 0),
            ],
        ];
    }

    /** Handles suggestions for the product search service workflow. */
    public function suggestions(string $q, int $limit = 8): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }

        $safeLimit = min(12, max(1, $limit));
        $normalized = Str::lower(preg_replace('/\s+/', ' ', trim($q)) ?: $q);

        return $this->cache->remember(
            'suggestions:'.$normalized.':'.$safeLimit,
            (int) config('vsn.performance.suggestion_cache_seconds', 30),
            function () use ($q, $safeLimit): array {
                $query = Product::query()->published()->with(['images.mediaAsset', 'vendor']);
                $this->applyText($query, $q);

                return $query->orderByDesc('sold_count')
                    ->limit($safeLimit)
                    ->get()
                    ->map(fn ($p) => [
                        'id' => $p->public_id,
                        'slug' => $p->slug,
                        'name' => $p->name,
                        'priceMinor' => $p->base_price_minor,
                        'image' => $p->images->first()?->publicUrl(),
                        'vendor' => $p->vendor?->name,
                    ])
                    ->all();
            }
        );
    }
}
