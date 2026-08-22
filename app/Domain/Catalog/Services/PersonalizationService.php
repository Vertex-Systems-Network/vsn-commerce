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
use Illuminate\Support\Collection;
/** Defines the PersonalizationService class and its project responsibilities. */
class PersonalizationService
{
    /** Handles recommendations for the personalization service workflow. */
    public function recommendations(?User $user,Request $request,int $limit=12):array
    {
        $limit=min(24,max(1,$limit));$categoryWeights=[];$vendorWeights=[];$exclude=[];
        if($user){
            $views=ProductView::query()->where('user_id',$user->id)->with('product:id,category_id,vendor_id')->latest('viewed_at')->limit(50)->get();
            foreach($views as $i=>$v){$w=max(1,10-intdiv($i,5));if($v->product){$categoryWeights[$v->product->category_id]=($categoryWeights[$v->product->category_id]??0)+$w;$vendorWeights[$v->product->vendor_id]=($vendorWeights[$v->product->vendor_id]??0)+intdiv($w+1,2);$exclude[$v->product_id]=true;}}
            $wish=WishlistItem::query()->where('user_id',$user->id)->with('product:id,category_id,vendor_id')->latest()->limit(30)->get();
            foreach($wish as $x){if($x->product){$categoryWeights[$x->product->category_id]=($categoryWeights[$x->product->category_id]??0)+12;$vendorWeights[$x->product->vendor_id]=($vendorWeights[$x->product->vendor_id]??0)+6;}}
            $bought=OrderItem::query()->whereHas('order',/** Inline callback for this operation. */ fn($q)=>$q->where('user_id',$user->id)->whereIn('payment_status',[PaymentStatus::Paid->value,PaymentStatus::PartiallyRefunded->value])->whereNotIn('status',[OrderStatus::Cancelled->value,OrderStatus::Refunded->value]))->with('product:id,category_id,vendor_id')->latest('id')->limit(60)->get();
            foreach($bought as $x){if($x->product){$categoryWeights[$x->product->category_id]=($categoryWeights[$x->product->category_id]??0)+8;$vendorWeights[$x->product->vendor_id]=($vendorWeights[$x->product->vendor_id]??0)+4;}}
        }
        $candidates=Product::query()->published()->with(['vendor','category','images.mediaAsset','variants.inventories'])->whereHas('variants.inventories',/** Inline callback for this operation. */ fn($q)=>$q->whereRaw('on_hand > (reserved + safety_stock)'))->orderByDesc('sold_count')->orderByDesc('rating')->limit(160)->get();
        $ranked=$candidates->map(/** Inline callback for this operation. */ function(Product $p)use($categoryWeights,$vendorWeights,$exclude,$user){$score=(int)$p->sold_count+((float)$p->rating*10);$reason='Popular in the marketplace';if($user){$cw=$categoryWeights[$p->category_id]??0;$vw=$vendorWeights[$p->vendor_id]??0;$score+=($cw*100)+($vw*40)-isset($exclude[$p->id])*40;if($cw>0)$reason='Because you explored '.$p->category?->name;elseif($vw>0)$reason='More from stores you engage with';}return ['product'=>$p,'score'=>$score,'reason'=>$reason];})->sortByDesc('score')->take($limit)->values();
        return $ranked->map(/** Inline callback for this operation. */ fn($row)=>['product'=>(new ProductResource($row['product']))->resolve($request),'reason'=>$row['reason']])->all();
    }
    /** Handles recent for the personalization service workflow. */
    public function recent(User $user,Request $request,int $limit=12):array
    {
        $rows=ProductView::query()->where('user_id',$user->id)->with(['product.vendor','product.category','product.images.mediaAsset','product.variants.inventories'])->latest('viewed_at')->limit(100)->get()->unique('product_id')->filter(/** Inline callback for this operation. */ fn($v)=>$v->product?->status?->value==='published')->take(min(30,max(1,$limit)));
        return $rows->map(/** Inline callback for this operation. */ fn($v)=>['viewedAt'=>$v->viewed_at?->toIso8601String(),'product'=>(new ProductResource($v->product))->resolve($request)])->values()->all();
    }
    /** Handles buy again for the personalization service workflow. */
    public function buyAgain(User $user,Request $request,int $limit=12):array
    {
        $items=OrderItem::query()->whereHas('order',/** Inline callback for this operation. */ fn($q)=>$q->where('user_id',$user->id)->whereIn('payment_status',[PaymentStatus::Paid->value,PaymentStatus::PartiallyRefunded->value])->whereIn('status',[OrderStatus::Delivered->value,OrderStatus::PartiallyRefunded->value]))->whereColumn('refunded_quantity','<','quantity')->with(['order','product.vendor','product.category','product.images.mediaAsset','product.variants.inventories','variant.inventories'])->latest('id')->limit(150)->get()->unique(/** Inline callback for this operation. */ fn($x)=>$x->product_id.':'.($x->product_variant_id??0))->filter(/** Inline callback for this operation. */ fn($x)=>$x->product?->status?->value==='published')->take(min(30,max(1,$limit)));
        return $items->map(/** Inline callback for this operation. */ function($x)use($request){$variant=$x->variant;$stock=$variant?->inventories?->sum(/** Inline callback for this operation. */ fn($i)=>$i->available())??0;return ['lastPurchasedAt'=>$x->order?->placed_at?->toIso8601String(),'previousUnitPriceMinor'=>$x->unit_price_minor,'variantId'=>$variant?->id,'variantName'=>$x->variant_name,'available'=>(bool)($variant?->is_active && $stock>0),'product'=>(new ProductResource($x->product))->resolve($request)];})->values()->all();
    }
}
