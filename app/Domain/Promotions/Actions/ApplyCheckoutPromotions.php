<?php

namespace App\Domain\Promotions\Actions;

use App\Domain\Checkout\Exceptions\CheckoutValidationException;
use App\Domain\Checkout\Services\CouponDiscountResolver;
use App\Domain\Reviews\Actions\ReserveReviewCoupon;
use App\Models\CheckoutPromotionAllocation;
use App\Models\CheckoutSession;
use App\Models\Promotion;
use App\Models\PromotionCode;
use App\Models\PromotionUsage;
use App\Models\ReviewRewardCoupon;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Defines the ApplyCheckoutPromotions class and its project responsibilities. */
class ApplyCheckoutPromotions
{
    /** Initializes the ApplyCheckoutPromotions instance and its dependencies. */
    public function __construct(
        private readonly CouponDiscountResolver $reviewCoupons,
        private readonly ReserveReviewCoupon $reserveReviewCoupon,
    ) {}

    /** @return array{discountMinor:int,platformMinor:int,sellerMinor:int,reviewCoupon:?ReviewRewardCoupon,sources:array<int,array<string,mixed>>} */
    public function execute(User $user, CheckoutSession $session, ?string $code): array
    {
        $session->loadMissing(['items.product.category','items.vendor']);
        $subtotal = (int) $session->items->sum('line_total_minor');
        if ($subtotal <= 0) return ['discountMinor'=>0,'platformMinor'=>0,'sellerMinor'=>0,'reviewCoupon'=>null,'sources'=>[]];

        $code = mb_strtoupper(trim((string) $code));
        $reviewCoupon = null;
        $promotionCode = null;
        $codedPromotion = null;

        if ($code !== '') {
            $candidate = ReviewRewardCoupon::query()->whereRaw('upper(code) = ?', [$code])->first();
            if ($candidate) {
                $resolved = $this->reviewCoupons->resolve($user, $code, $subtotal);
                $reviewCoupon = $resolved['coupon'];
            } else {
                $promotionCode = PromotionCode::query()->whereRaw('upper(code) = ?', [$code])->where('status','active')->with('promotion.scopes')->lockForUpdate()->first();
                if (! $promotionCode || ! $promotionCode->promotion?->isLive() || $promotionCode->promotion->kind !== 'coupon') {
                    throw new CheckoutValidationException('This coupon or promotion code is invalid or inactive.', 'couponCode');
                }
                $codedPromotion = $promotionCode->promotion;
            }
        }

        $automatic = Promotion::query()
            ->where('status','active')
            ->whereIn('kind',['automatic','flash'])
            ->where(/** Inline callback for this operation. */ fn($q)=>$q->whereNull('starts_at')->orWhere('starts_at','<=',now()))
            ->where(/** Inline callback for this operation. */ fn($q)=>$q->whereNull('ends_at')->orWhere('ends_at','>',now()))
            ->with('scopes')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->filter(/** Inline callback for this operation. */ fn(Promotion $p)=>$this->eligibleItems($p,$session->items)->isNotEmpty())
            ->values();

        if (($session->metadata['purpose'] ?? null) === 'gift') {
            $automatic = $automatic->filter(/** Inline callback for this operation. */ fn(Promotion $p)=>(bool)$p->applies_to_gifts)->values();
            if ($codedPromotion && ! $codedPromotion->applies_to_gifts) {
                throw new CheckoutValidationException('This promotion cannot be used for gift checkout.', 'couponCode');
            }
        }

        $selected = collect();
        if ($codedPromotion) {
            $this->assertPromotionEligible($codedPromotion,$session->items,'couponCode');
            if ($codedPromotion->stacking_mode === 'exclusive') {
                $selected->push(['promotion'=>$codedPromotion,'code'=>$promotionCode]);
            } else {
                $selected->push(['promotion'=>$codedPromotion,'code'=>$promotionCode]);
                foreach ($automatic as $p) if ($p->stacking_mode === 'stackable' && $p->can_stack_with_coupon) $selected->push(['promotion'=>$p,'code'=>null]);
            }
        } elseif ($reviewCoupon) {
            foreach ($automatic as $p) if ($p->stacking_mode === 'stackable' && $p->can_stack_with_review_coupon) $selected->push(['promotion'=>$p,'code'=>null]);
        } else {
            $exclusive = $automatic->first(/** Inline callback for this operation. */ fn(Promotion $p)=>$p->stacking_mode === 'exclusive');
            if ($exclusive) $selected->push(['promotion'=>$exclusive,'code'=>null]);
            else foreach ($automatic as $p) $selected->push(['promotion'=>$p,'code'=>null]);
        }

        $remaining = $session->items->mapWithKeys(/** Inline callback for this operation. */ fn($item)=>[$item->id=>(int)$item->line_total_minor])->all();
        $maxDiscount = intdiv($subtotal * (int) config('vsn.promotions.max_total_discount_bps', 9000), 10_000);
        $discountUsed = 0;
        $sources = [];

        foreach ($selected as $selection) {
            /** @var Promotion $promotion */
            $promotion = $selection['promotion'];
            $eligible = $this->eligibleItems($promotion,$session->items)->filter(/** Inline callback for this operation. */ fn($item)=>($remaining[$item->id]??0)>0)->values();
            $eligibleSubtotal = (int) $eligible->sum(/** Inline callback for this operation. */ fn($item)=>$remaining[$item->id]??0);
            if ($eligibleSubtotal < (int)$promotion->minimum_subtotal_minor || $eligibleSubtotal <= 0) continue;

            $allocations = $this->calculateAllocations($promotion,$eligible,$remaining,min($maxDiscount-$discountUsed,$eligibleSubtotal));
            $total = array_sum(array_column($allocations,'discount'));
            if ($total <= 0) continue;

            $usage = $this->reserveUsage($user,$session,$promotion,$selection['code'],$total,$allocations);
            if (! $usage) continue;
            foreach ($allocations as $row) {
                if ($row['discount'] <= 0) continue;
                CheckoutPromotionAllocation::create([
                    'checkout_session_id'=>$session->id,
                    'checkout_session_item_id'=>$row['item']->id,
                    'promotion_id'=>$promotion->id,
                    'promotion_usage_id'=>$usage->id,
                    'source_type'=>'promotion',
                    'source_reference'=>$selection['code']?->code ?? $promotion->slug,
                    'discount_minor'=>$row['discount'],
                    'platform_funded_minor'=>$row['platform'],
                    'seller_funded_minor'=>$row['seller'],
                    'metadata'=>['promotion_name'=>$promotion->name,'kind'=>$promotion->kind,'funding_mode'=>$promotion->funding_mode],
                ]);
                $remaining[$row['item']->id] -= $row['discount'];
            }
            $discountUsed += $total;
            $sources[]=['type'=>'promotion','id'=>$promotion->public_id,'name'=>$promotion->name,'kind'=>$promotion->kind,'code'=>$selection['code']?->code,'discountMinor'=>$total];
            if ($discountUsed >= $maxDiscount) break;
        }

        if ($reviewCoupon && $discountUsed < $maxDiscount) {
            $bps = (int) $reviewCoupon->percent_bps;
            $reviewRows=[];$reviewTotal=0;
            foreach ($session->items as $item) {
                $base=max(0,$remaining[$item->id]??0); if($base<=0)continue;
                $discount=min(intdiv($base*$bps,10_000),$maxDiscount-$discountUsed-$reviewTotal);
                if($discount<=0)continue;
                $reviewRows[]=['item'=>$item,'discount'=>$discount];$reviewTotal+=$discount;
                if($discountUsed+$reviewTotal >= $maxDiscount)break;
            }
            if($reviewTotal>0){
                $this->reserveReviewCoupon->execute($user,$reviewCoupon,$session);
                foreach($reviewRows as $row){
                    CheckoutPromotionAllocation::create([
                        'checkout_session_id'=>$session->id,'checkout_session_item_id'=>$row['item']->id,'source_type'=>'review_reward','source_reference'=>$reviewCoupon->code,
                        'discount_minor'=>$row['discount'],'platform_funded_minor'=>$row['discount'],'seller_funded_minor'=>0,'metadata'=>['percent_bps'=>$bps],
                    ]);
                    $remaining[$row['item']->id]-=$row['discount'];
                }
                $discountUsed+=$reviewTotal;
                $sources[]=['type'=>'review_reward','code'=>$reviewCoupon->code,'name'=>'Verified review reward','discountMinor'=>$reviewTotal];
            }
        }

        $rows=CheckoutPromotionAllocation::query()->where('checkout_session_id',$session->id)->get();
        return [
            'discountMinor'=>(int)$rows->sum('discount_minor'),
            'platformMinor'=>(int)$rows->sum('platform_funded_minor'),
            'sellerMinor'=>(int)$rows->sum('seller_funded_minor'),
            'reviewCoupon'=>$reviewCoupon,
            'sources'=>$sources,
        ];
    }

    /** Handles assert promotion eligible for the apply checkout promotions workflow. */
    private function assertPromotionEligible(Promotion $promotion, Collection $items, string $field): void
    {
        $eligible=$this->eligibleItems($promotion,$items);
        $eligibleSubtotal=(int)$eligible->sum('line_total_minor');
        if (! $promotion->isLive() || $eligible->isEmpty() || $eligibleSubtotal < (int)$promotion->minimum_subtotal_minor) {
            throw new CheckoutValidationException('This promotion does not apply to the current cart.',$field);
        }
    }

    /** Handles eligible items for the apply checkout promotions workflow. */
    private function eligibleItems(Promotion $promotion, Collection $items): Collection
    {
        $scopes=$promotion->scopes;
        return $items->filter(/** Inline callback for this operation. */ function($item)use($promotion,$scopes):bool{
            if($promotion->vendor_id && (int)$item->vendor_id !== (int)$promotion->vendor_id)return false;
            if($scopes->isEmpty() || $scopes->contains(/** Inline callback for this operation. */ fn($s)=>$s->scope_type==='all'))return true;
            return $scopes->contains(/** Inline callback for this operation. */ function($scope)use($item):bool{
                if($scope->scope_type==='product')return (int)$scope->product_id===(int)$item->product_id;
                if($scope->scope_type==='category')return (int)$scope->category_id===(int)$item->product?->category_id;
                return false;
            });
        });
    }

    /** @return array<int,array{item:mixed,discount:int,platform:int,seller:int}> */
    private function calculateAllocations(Promotion $promotion, Collection $items, array $remaining, int $cap): array
    {
        if($cap<=0)return [];
        $rows=[];$raw=[];$eligibleTotal=(int)$items->sum(/** Inline callback for this operation. */ fn($i)=>max(0,$remaining[$i->id]??0));
        if($eligibleTotal<=0)return [];
        if($promotion->discount_type==='percent'){
            foreach($items as $item)$raw[$item->id]=intdiv(max(0,$remaining[$item->id]??0)*(int)$promotion->percent_bps,10_000);
        }else{
            $fixed=min((int)$promotion->fixed_minor,$eligibleTotal,$cap);$used=0;
            foreach($items as $item){$share=intdiv($fixed*max(0,$remaining[$item->id]??0),$eligibleTotal);$raw[$item->id]=$share;$used+=$share;}
            $remainder=$fixed-$used;foreach($items as $item){if($remainder<=0)break;$raw[$item->id]++;$remainder--;}
        }
        $totalRaw=array_sum($raw); if($totalRaw>$cap&&$totalRaw>0){$scaled=[];$used=0;foreach($items as $item){$v=intdiv(($raw[$item->id]??0)*$cap,$totalRaw);$scaled[$item->id]=$v;$used+=$v;}$rem=$cap-$used;foreach($items as $item){if($rem<=0)break;if(($raw[$item->id]??0)>0){$scaled[$item->id]++;$rem--;}}$raw=$scaled;}
        $share=match($promotion->funding_mode){'seller'=>0,'shared'=>(int)$promotion->platform_share_bps,default=>10_000};
        foreach($items as $item){$discount=min((int)($raw[$item->id]??0),max(0,$remaining[$item->id]??0));if($discount<=0)continue;$platform=intdiv($discount*$share,10_000);$rows[]=['item'=>$item,'discount'=>$discount,'platform'=>$platform,'seller'=>$discount-$platform];}
        return $rows;
    }

    /** Handles reserve usage for the apply checkout promotions workflow. */
    private function reserveUsage(User $user, CheckoutSession $session, Promotion $promotion, ?PromotionCode $code, int $discount, array $allocations): ?PromotionUsage
    {
        $promotion=Promotion::query()->whereKey($promotion->id)->lockForUpdate()->firstOrFail();
        $activeStatuses=['reserved','redeemed'];
        if($promotion->max_redemptions!==null && PromotionUsage::query()->where('promotion_id',$promotion->id)->whereIn('status',$activeStatuses)->count()>=(int)$promotion->max_redemptions){
            if(!$code)return null;
            throw new CheckoutValidationException('This promotion has reached its redemption limit.','couponCode');
        }
        if($promotion->per_user_limit!==null && PromotionUsage::query()->where('promotion_id',$promotion->id)->where('user_id',$user->id)->whereIn('status',$activeStatuses)->count()>=(int)$promotion->per_user_limit){
            if(!$code)return null;
            throw new CheckoutValidationException('You have already used this promotion the maximum number of times.','couponCode');
        }
        if($code){
            $code=PromotionCode::query()->whereKey($code->id)->lockForUpdate()->firstOrFail();
            if($code->status!=='active')throw new CheckoutValidationException('This promotion code is inactive.','couponCode');
            if($code->max_redemptions!==null && PromotionUsage::query()->where('promotion_code_id',$code->id)->whereIn('status',$activeStatuses)->count()>=(int)$code->max_redemptions)throw new CheckoutValidationException('This promotion code has reached its redemption limit.','couponCode');
            if($code->per_user_limit!==null && PromotionUsage::query()->where('promotion_code_id',$code->id)->where('user_id',$user->id)->whereIn('status',$activeStatuses)->count()>=(int)$code->per_user_limit)throw new CheckoutValidationException('You have already used this promotion code the maximum number of times.','couponCode');
        }
        $platform=array_sum(array_column($allocations,'platform'));$seller=array_sum(array_column($allocations,'seller'));
        return PromotionUsage::create(['public_id'=>(string)Str::ulid(),'promotion_id'=>$promotion->id,'promotion_code_id'=>$code?->id,'user_id'=>$user->id,'checkout_session_id'=>$session->id,'status'=>'reserved','discount_minor'=>$discount,'platform_funded_minor'=>$platform,'seller_funded_minor'=>$seller,'reserved_at'=>now(),'metadata'=>['promotion_name'=>$promotion->name]]);
    }
}
