<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reviews\Actions\SubmitVerifiedReview;
use App\Domain\Reviews\Exceptions\ReviewException;
use App\Domain\Reviews\Services\ReviewEligibility;
use App\Domain\Security\Services\SecureUploadInspector;
use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reviews\SubmitReviewRequest;
use App\Http\Resources\ReviewCouponResource;
use App\Http\Resources\ReviewResource;
use App\Models\Product;
use App\Models\Review;
use App\Models\ReviewRewardCoupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Defines the ReviewController class and its project responsibilities. */
class ReviewController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(Request $request, ReviewEligibility $eligibility): JsonResponse
    {
        $user = $request->user();
        $pending = $eligibility->pending($user)->map(/** Inline callback for this operation. */ fn ($item) => [
            'orderItemId' => $item->id,
            'orderId' => $item->order?->public_id,
            'productId' => $item->product_id,
            'productSlug' => $item->product?->slug,
            'productName' => $item->product?->name ?? $item->product_name,
            'variantName' => $item->variant_name,
            'image' => $item->product?->images?->first()?->url,
            'deliveredAt' => $item->order?->delivered_at?->toISOString(),
        ])->values();

        $reviews = Review::query()->where('user_id', $user->id)
            ->with(['user', 'order', 'orderItem', 'product.images', 'product.vendor', 'images', 'sellerReplier', 'rewardCoupon.review.product', 'rewardCoupon.review.orderItem'])
            ->latest('submitted_at')->get();
        $coupons = ReviewRewardCoupon::query()->where('user_id', $user->id)
            ->with(['review.product', 'review.orderItem'])->latest('issued_at')->get();

        return response()->json(['data' => [
            'pending' => $pending,
            'reviews' => ReviewResource::collection($reviews)->resolve($request),
            'coupons' => ReviewCouponResource::collection($coupons)->resolve($request),
        ]]);
    }

    /** Handles the store request for this resource. */
    public function store(
        SubmitReviewRequest $request,
        SubmitVerifiedReview $action,
        SecureUploadInspector $uploads,
    ): JsonResponse {
        $data = $request->validated();
        foreach ($request->file('images', []) as $image) {
            $uploads->inspect($image, ['image/jpeg', 'image/png', 'image/webp'], 5_242_880, true);
        }

        try {
            $review = $action->execute(
                $request->user(),
                (int) $data['orderItemId'],
                (int) $data['rating'],
                $data['text'],
                $request->file('images', []),
            );
        } catch (ReviewException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => [$exception->field => [$exception->getMessage()]],
            ], 422);
        }

        return response()->json([
            'data' => (new ReviewResource($review))->resolve($request),
        ], 201);
    }

    /** Handles product for the review controller workflow. */
    public function product(Product $product, Request $request): JsonResponse
    {
        abort_unless($product->status->value === 'published', 404);
        $reviews = Review::query()
            ->where('product_id', $product->id)
            ->where('status', ReviewStatus::Approved->value)
            ->with(['user', 'order', 'orderItem', 'product.images', 'product.vendor', 'images', 'sellerReplier'])
            ->latest('submitted_at')
            ->paginate(min(50, max(1, (int) $request->integer('perPage', 10))));

        return response()->json(['data' => [
            'items' => ReviewResource::collection($reviews->getCollection())->resolve($request),
            'meta' => [
                'currentPage' => $reviews->currentPage(),
                'lastPage' => $reviews->lastPage(),
                'total' => $reviews->total(),
            ],
        ]]);
    }
}
