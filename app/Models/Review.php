<?php

namespace App\Models;

use App\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Defines the Review class and its project responsibilities. */
class Review extends Model
{
    use HasFactory;

    protected $fillable = ['public_id','user_id','order_id','order_item_id','product_id','product_variant_id','status','rating','body','seller_reply','seller_replied_by','seller_replied_at','verified_purchase','helpful_count','report_count','submitted_at','moderated_at','moderated_by','moderation_note','metadata'];
    /** Handles casts for the review workflow. */
    protected function casts(): array { return ['status'=>ReviewStatus::class,'rating'=>'integer','verified_purchase'=>'boolean','helpful_count'=>'integer','report_count'=>'integer','seller_replied_at'=>'datetime','submitted_at'=>'datetime','moderated_at'=>'datetime','metadata'=>'array']; }
    /** Returns route key name. */
    public function getRouteKeyName(): string { return 'public_id'; }
    /** Handles user for the review workflow. */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    /** Handles order for the review workflow. */
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    /** Handles order item for the review workflow. */
    public function orderItem(): BelongsTo { return $this->belongsTo(OrderItem::class); }
    /** Handles product for the review workflow. */
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    /** Handles variant for the review workflow. */
    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'product_variant_id'); }
    /** Handles moderator for the review workflow. */
    public function moderator(): BelongsTo { return $this->belongsTo(User::class, 'moderated_by'); }
    /** Handles images for the review workflow. */
    public function images(): HasMany { return $this->hasMany(ReviewImage::class)->orderBy('sort_order'); }
    /** Handles reward coupon for the review workflow. */
    public function rewardCoupon(): HasOne { return $this->hasOne(ReviewRewardCoupon::class); }
    /** Handles seller replier for the review workflow. */
    public function sellerReplier(): BelongsTo { return $this->belongsTo(User::class, 'seller_replied_by'); }
    /** Handles helpful votes for the review workflow. */
    public function helpfulVotes(): HasMany { return $this->hasMany(ReviewHelpfulVote::class); }
    /** Handles reports for the review workflow. */
    public function reports(): HasMany { return $this->hasMany(ReviewReport::class); }
}
