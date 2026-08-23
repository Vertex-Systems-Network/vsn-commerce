<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Actions\CreateProductAlert;
use App\Enums\ProductAlertStatus;
use App\Enums\ProductAlertType;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductAlertResource;
use App\Models\Product;
use App\Models\ProductAlert;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Defines the ProductAlertController class and its project responsibilities. */
class ProductAlertController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(Request $request): JsonResponse
    {
        $rows = ProductAlert::query()
            ->where('user_id', $request->user()->id)
            ->with(['product.images', 'variant'])
            ->whereIn('status', [ProductAlertStatus::Active->value, ProductAlertStatus::Triggered->value])
            ->latest()
            ->get();

        return response()->json([
            'data' => ProductAlertResource::collection($rows)->resolve($request),
        ]);
    }

    /** Handles the store request for this resource. */
    public function store(Request $request, Product $product, CreateProductAlert $action): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|in:price_drop,back_in_stock',
            'variantId' => 'nullable|integer',
            'targetPriceMinor' => 'nullable|integer|min:1',
        ]);
        $variant = ! empty($data['variantId'])
            ? ProductVariant::query()->whereKey($data['variantId'])->firstOrFail()
            : null;
        $row = $action->execute(
            $request->user(),
            $product,
            ProductAlertType::from($data['type']),
            $variant,
            isset($data['targetPriceMinor']) ? (int) $data['targetPriceMinor'] : null,
        );

        return response()->json([
            'data' => (new ProductAlertResource($row->load(['product.images', 'variant'])))->resolve($request),
        ]);
    }

    /** Handles the destroy request for this resource. */
    public function destroy(Request $request, ProductAlert $productAlert): JsonResponse
    {
        abort_unless($productAlert->user_id === $request->user()->id, 404);
        $productAlert->update(['status' => ProductAlertStatus::Disabled->value]);

        return response()->json([
            'data' => ['removed' => true, 'id' => $productAlert->public_id],
        ]);
    }
}
