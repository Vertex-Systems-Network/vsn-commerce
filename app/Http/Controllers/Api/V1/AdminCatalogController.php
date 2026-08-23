<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Services\CatalogCache;
use App\Domain\Catalog\Services\CatalogMutationService;
use App\Enums\ProductStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\CatalogManagementProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Defines the AdminCatalogController class and its project responsibilities. */
class AdminCatalogController extends Controller
{
    /** Initializes the controller cache dependency. */
    public function __construct(private readonly CatalogCache $cache) {}

    /** Handles the index request for this resource. */
    public function index(Request $request): JsonResponse
    {
        $this->admin($request);
        $q = Product::query()
            ->with(['vendor', 'category', 'taxClass', 'images.mediaAsset', 'variants.inventories'])
            ->when($request->string('status')->toString(), fn ($x, $s) => $x->where('status', $s))
            ->when($request->string('q')->toString(), fn ($x, $s) => $x->where('name', 'like', '%'.$s.'%'))
            ->latest();
        $rows = $q->paginate(60);

        return response()->json(['data' => [
            'items' => CatalogManagementProductResource::collection($rows->getCollection())->resolve($request),
            'meta' => [
                'total' => $rows->total(),
                'currentPage' => $rows->currentPage(),
                'lastPage' => $rows->lastPage(),
            ],
            'categories' => $this->categoryRows(),
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name', 'slug', 'status']),
        ]]);
    }

    /** Handles the show request for this resource. */
    public function show(Request $request, Product $product): CatalogManagementProductResource
    {
        $this->admin($request);

        return new CatalogManagementProductResource($product->load([
            'vendor',
            'category',
            'taxClass',
            'images.mediaAsset',
            'variants.inventories',
        ]));
    }

    /** Handles the store request for this resource. */
    public function store(Request $request, CatalogMutationService $service): CatalogManagementProductResource
    {
        $this->admin($request);
        $d = $this->validated($request);
        $vendor = Vendor::query()->findOrFail($d['vendorId']);
        unset($d['vendorId']);

        return new CatalogManagementProductResource($service->create($vendor, $request->user(), $d, true));
    }

    /** Handles the update request for this resource. */
    public function update(Request $request, Product $product, CatalogMutationService $service): CatalogManagementProductResource
    {
        $this->admin($request);

        return new CatalogManagementProductResource($service->update($product, $request->user(), $this->validated($request, true), true));
    }

    /** Handles review for the admin catalog controller workflow. */
    public function review(Request $request, Product $product): CatalogManagementProductResource
    {
        $this->admin($request);
        $d = $request->validate([
            'status' => 'required|in:published,draft,suspended,archived',
            'note' => 'nullable|string|max:1000',
        ]);
        $meta = $product->metadata ?? [];
        $meta['last_catalog_review'] = [
            'status' => $d['status'],
            'note' => $d['note'] ?? null,
            'actor' => $request->user()->id,
            'at' => now()->toIso8601String(),
        ];
        $product->update(['status' => ProductStatus::from($d['status']), 'metadata' => $meta]);
        $this->cache->bump();

        return new CatalogManagementProductResource($product->fresh([
            'vendor',
            'category',
            'taxClass',
            'images.mediaAsset',
            'variants.inventories',
        ]));
    }

    /** Handles categories for the admin catalog controller workflow. */
    public function categories(Request $request): JsonResponse
    {
        $this->admin($request);

        return response()->json(['data' => $this->categoryRows()]);
    }

    /** Handles store category for the admin catalog controller workflow. */
    public function storeCategory(Request $request): JsonResponse
    {
        $this->admin($request);
        $d = $request->validate([
            'name' => 'required|string|max:120',
            'slug' => 'nullable|string|max:160',
            'parentId' => 'nullable|integer|exists:categories,id',
            'isActive' => 'nullable|boolean',
            'sortOrder' => 'nullable|integer|min:0',
        ]);
        $category = Category::create([
            'name' => $d['name'],
            'slug' => $this->categorySlug($d['slug'] ?? $d['name']),
            'parent_id' => $d['parentId'] ?? null,
            'is_active' => $d['isActive'] ?? true,
            'sort_order' => $d['sortOrder'] ?? 0,
        ]);
        $this->cache->bump();

        return response()->json(['data' => $category], 201);
    }

    /** Handles update category for the admin catalog controller workflow. */
    public function updateCategory(Request $request, Category $category): JsonResponse
    {
        $this->admin($request);
        $d = $request->validate([
            'name' => 'sometimes|string|max:120',
            'slug' => 'sometimes|string|max:160',
            'parentId' => 'sometimes|nullable|integer|exists:categories,id',
            'isActive' => 'sometimes|boolean',
            'sortOrder' => 'sometimes|integer|min:0',
        ]);
        abort_if(isset($d['parentId']) && (int) $d['parentId'] === $category->id, 422, 'Category cannot be its own parent.');
        $category->update(array_filter([
            'name' => $d['name'] ?? null,
            'slug' => isset($d['slug']) ? $this->categorySlug($d['slug'], $category->id) : null,
            'parent_id' => array_key_exists('parentId', $d) ? $d['parentId'] : null,
            'is_active' => $d['isActive'] ?? null,
            'sort_order' => $d['sortOrder'] ?? null,
        ], fn ($value) => $value !== null));
        $this->cache->bump();

        return response()->json(['data' => $category->fresh()]);
    }

    /** Handles stock for the admin catalog controller workflow. */
    public function stock(Request $request, ProductVariant $variant, CatalogMutationService $service): JsonResponse
    {
        $this->admin($request);
        $d = $request->validate([
            'onHand' => 'required|integer|min:0',
            'safetyStock' => 'nullable|integer|min:0',
            'reason' => 'nullable|string|max:190',
        ]);
        $row = $service->setStock(
            $variant,
            $request->user(),
            (int) $d['onHand'],
            isset($d['safetyStock']) ? (int) $d['safetyStock'] : null,
            $d['reason'] ?? 'admin_adjustment'
        );

        return response()->json(['data' => [
            'variantId' => $variant->id,
            'onHand' => $row->on_hand,
            'reserved' => $row->reserved,
            'safetyStock' => $row->safety_stock,
            'available' => $row->available(),
        ]]);
    }

    /** Handles admin for the admin catalog controller workflow. */
    private function admin(Request $request): void
    {
        $role = $request->user()?->role instanceof UserRole
            ? $request->user()->role->value
            : (string) $request->user()?->role;
        abort_unless(in_array($role, [UserRole::Admin->value, UserRole::SuperAdmin->value], true), 403);
    }

    /** Handles category rows for the admin catalog controller workflow. */
    private function categoryRows(): array
    {
        return Category::query()
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'parentId' => $category->parent_id,
                'isActive' => $category->is_active,
                'sortOrder' => $category->sort_order,
                'productsCount' => $category->products_count,
            ])
            ->all();
    }

    /** Handles category slug for the admin catalog controller workflow. */
    private function categorySlug(string $value, ?int $except = null): string
    {
        $base = Str::slug($value) ?: 'category';
        $slug = $base;
        $number = 2;
        while (Category::query()
            ->where('slug', $slug)
            ->when($except, fn ($query) => $query->where('id', '!=', $except))
            ->exists()) {
            $slug = $base.'-'.$number++;
        }

        return $slug;
    }

    /** Validates admin catalog fields; product images are managed only through media endpoints. */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'vendorId' => [$partial ? 'sometimes' : 'required', 'integer', 'exists:vendors,id'],
            'name' => [$required, 'string', 'max:190'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:190'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:120', Rule::unique('products', 'sku')->ignore($request->route('product')?->id)],
            'categoryId' => [$required, 'integer', 'exists:categories,id'],
            'shortDescription' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'basePriceMinor' => [$required, 'integer', 'min:1'],
            'compareAtPriceMinor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'installmentEnabled' => ['sometimes', 'boolean'],
            'gameEnabled' => ['sometimes', 'boolean'],
            'taxClassId' => ['sometimes', 'nullable', 'string', 'exists:tax_classes,public_id'],
            'priceIncludesTax' => ['sometimes', 'nullable', 'boolean'],
            'status' => ['sometimes', 'in:draft,pending_review,published,suspended,archived'],
            'images' => ['prohibited'],
            'variants' => [$required, 'array', 'min:1', 'max:100'],
            'variants.*.id' => ['sometimes', 'integer'],
            'variants.*.sku' => ['sometimes', 'nullable', 'string', 'max:120'],
            'variants.*.name' => ['required_with:variants', 'string', 'max:160'],
            'variants.*.options' => ['sometimes', 'array'],
            'variants.*.priceMinor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'variants.*.compareAtPriceMinor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'variants.*.isDefault' => ['sometimes', 'boolean'],
            'variants.*.isActive' => ['sometimes', 'boolean'],
            'variants.*.stock' => ['sometimes', 'integer', 'min:0'],
            'variants.*.safetyStock' => ['sometimes', 'integer', 'min:0'],
        ]);
    }
}
