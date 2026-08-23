<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Actions\RecordProductView;
use App\Domain\Catalog\Services\PersonalizationService;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Defines the PersonalizationController class and its project responsibilities. */
class PersonalizationController extends Controller
{
    /** Handles recommendations for the personalization controller workflow. */
    public function recommendations(Request $request, PersonalizationService $service): JsonResponse
    {
        $user = $request->user() ?: Auth::guard('sanctum')->user();

        return response()->json([
            'data' => [
                'items' => $service->recommendations($user, $request, (int) $request->input('limit', 12)),
                'personalized' => (bool) $user,
            ],
        ]);
    }

    /** Handles recent for the personalization controller workflow. */
    public function recent(Request $request, PersonalizationService $service): JsonResponse
    {
        return response()->json([
            'data' => [
                'items' => $service->recent($request->user(), $request, (int) $request->input('limit', 12)),
            ],
        ]);
    }

    /** Handles buy again for the personalization controller workflow. */
    public function buyAgain(Request $request, PersonalizationService $service): JsonResponse
    {
        return response()->json([
            'data' => [
                'items' => $service->buyAgain($request->user(), $request, (int) $request->input('limit', 12)),
            ],
        ]);
    }

    /** Handles clear recent for the personalization controller workflow. */
    public function clearRecent(Request $request): JsonResponse
    {
        $deleted = ProductView::query()
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json([
            'data' => ['deleted' => $deleted],
        ]);
    }

    /** Handles view for the personalization controller workflow. */
    public function view(Request $request, Product $product, RecordProductView $action): JsonResponse
    {
        $data = $request->validate([
            'variantId' => 'nullable|integer',
            'source' => 'nullable|string|max:40',
        ]);
        $user = $request->user() ?: Auth::guard('sanctum')->user();
        $device = trim((string) $request->header('X-Device-Id', ''));

        if (! $user && $device === '') {
            return response()->json([
                'data' => ['recorded' => false, 'viewedAt' => null],
            ]);
        }

        $row = $action->execute(
            $product,
            $user,
            $device !== '' ? $device : null,
            isset($data['variantId']) ? (int) $data['variantId'] : null,
            $data['source'] ?? 'product_detail',
        );

        return response()->json([
            'data' => [
                'recorded' => true,
                'viewedAt' => $row->viewed_at?->toIso8601String(),
            ],
        ]);
    }
}
