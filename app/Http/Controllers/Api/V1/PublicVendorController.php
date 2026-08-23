<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Services\VendorStorefrontMediaService;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Serves public vendor directory and vendor-storefront data without exposing seller-private records. */
class PublicVendorController extends Controller
{
    /** Creates the public storefront controller with reusable-media resolution. */
    public function __construct(private readonly VendorStorefrontMediaService $storefrontMedia) {}

    /** Lists active storefront-enabled vendors with marketplace-safe summary metrics. */
    public function index(Request $request): JsonResponse
    {
        $query = Vendor::query()->where('status', 'active')->withCount(['products' => /** Counts published storefront products only. */ fn ($q) => $q->where('status', 'published')]);
        if ($search = trim((string) $request->query('q'))) {
            $query->where('name', 'like', '%'.$search.'%');
        }
        $rows = $query->orderBy('name')->get()->filter(/** Hides sellers that explicitly disable their public storefront. */ fn (Vendor $vendor) => ($vendor->metadata['storefrontEnabled'] ?? true) !== false);

        return response()->json(['data' => ['items' => $rows->map(/** Serializes public seller cards. */ fn (Vendor $vendor) => $this->vendorRow($vendor))->values()]]);
    }

    /** Shows one active seller storefront and only that seller's published products. */
    public function show(string $slug): JsonResponse
    {
        $vendor = Vendor::query()->where('slug', $slug)->where('status', 'active')->firstOrFail();
        abort_if(($vendor->metadata['storefrontEnabled'] ?? true) === false, 404);
        $products = $vendor->products()->where('status', 'published')->with(['vendor', 'category', 'taxClass', 'images.mediaAsset', 'variants.inventories'])->latest()->get();

        return response()->json(['data' => [
            'vendor' => $this->vendorRow($vendor),
            'products' => ProductResource::collection($products)->resolve(request()),
        ]]);
    }

    /** Creates the public, privacy-safe storefront representation of a seller. */
    private function vendorRow(Vendor $vendor): array
    {
        $meta = $vendor->metadata ?? [];
        $logo = $this->storefrontMedia->logoPayload($vendor);

        return [
            'id' => $vendor->id, 'name' => $vendor->name, 'slug' => $vendor->slug, 'shopUrl' => '/shop/'.$vendor->slug,
            'logoMediaAssetId' => $logo['logoMediaAssetId'], 'logoUrl' => $logo['logoUrl'], 'logoAlt' => $logo['logoAlt'],
            'headline' => $meta['storefrontHeadline'] ?? null, 'description' => $meta['storefrontDescription'] ?? null,
            'supportEmail' => $meta['publicSupportEmail'] ?? null,
            'productsCount' => (int) ($vendor->products_count ?? $vendor->products()->where('status', 'published')->count()),
        ];
    }
}
