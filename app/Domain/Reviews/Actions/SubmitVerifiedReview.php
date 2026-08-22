<?php

namespace App\Domain\Reviews\Actions;

use App\Domain\Reviews\Exceptions\ReviewException;
use App\Domain\Reviews\Services\ReviewEligibility;
use App\Enums\ReviewStatus;
use App\Models\OrderItem;
use App\Models\Review;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Defines the SubmitVerifiedReview class and its project responsibilities. */
class SubmitVerifiedReview
{
    /** Initializes the SubmitVerifiedReview instance and its dependencies. */
    public function __construct(private readonly ReviewEligibility $eligibility, private readonly IssueReviewRewardCoupon $issueCoupon) {}

    /** @param array<int,UploadedFile> $images */
    public function execute(User $user, int $orderItemId, int $rating, string $body, array $images = []): Review
    {
        $stored = [];
        try {
            return DB::transaction(/** Inline callback for this operation. */ function () use ($user, $orderItemId, $rating, $body, $images, &$stored): Review {
                $item = OrderItem::query()->whereKey($orderItemId)->with(['order','product'])->lockForUpdate()->first();
                if (! $item || $item->order?->user_id !== $user->id) throw new ReviewException('The selected purchase item was not found.', 'orderItemId');

                $existing = Review::query()->where('order_item_id', $item->id)->first();
                if ($existing) {
                    if ($existing->user_id !== $user->id) throw new ReviewException('This purchase item has already been reviewed.', 'orderItemId');
                    return $existing->load(['images','rewardCoupon','product']);
                }

                if (! $this->eligibility->isEligible($item, $user)) {
                    throw new ReviewException('Only delivered, non-fully-refunded purchases can be reviewed.', 'orderItemId');
                }

                $review = Review::create([
                    'public_id'=>(string) Str::ulid(),
                    'user_id'=>$user->id,
                    'order_id'=>$item->order_id,
                    'order_item_id'=>$item->id,
                    'product_id'=>$item->product_id,
                    'product_variant_id'=>$item->product_variant_id,
                    'status'=>ReviewStatus::Pending,
                    'rating'=>$rating,
                    'body'=>trim($body),
                    'verified_purchase'=>true,
                    'submitted_at'=>now(),
                ]);

                $disk = (string) config('vsn.reviews.image_disk', 'public');
                foreach (array_values($images) as $index => $image) {
                    $sha=hash_file('sha256',$image->getRealPath());
                    [$width,$height]=@getimagesize($image->getRealPath())?:[null,null];
                    if(!$width||!$height) throw new ReviewException('A review image could not be validated.', 'images');
                    $path = $image->store("reviews/{$user->id}/{$review->public_id}", $disk);
                    $stored[] = [$disk, $path];
                    $review->images()->create([
                        'disk'=>$disk,
                        'path'=>$path,
                        'original_name'=>$image->getClientOriginalName(),
                        'mime_type'=>$image->getMimeType(),
                        'size_bytes'=>$image->getSize() ?: 0,
                        'sha256'=>$sha,
                        'width'=>$width,
                        'height'=>$height,
                        'sort_order'=>$index,
                    ]);
                }

                $this->issueCoupon->execute($review);
                return $review->load(['images','rewardCoupon','product','orderItem']);
            }, 3);
        } catch (\Throwable $exception) {
            foreach ($stored as [$disk, $path]) Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }
}
