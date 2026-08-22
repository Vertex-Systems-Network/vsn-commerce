<?php
namespace App\Domain\Promotions\Services;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\Promotion;
use Illuminate\Http\Request;
/** Defines the DealDiscoveryService class and its project responsibilities. */
class DealDiscoveryService
{
    /** Executes the deal discovery service operation. */
    public function execute(Request $request,int $limit=24):array
    {
        $promos=Promotion::query()->where('status','active')->whereIn('kind',['automatic','flash'])->where(/** Inline callback for this operation. */ fn($q)=>$q->whereNull('starts_at')->orWhere('starts_at','<=',now()))->where(/** Inline callback for this operation. */ fn($q)=>$q->whereNull('ends_at')->orWhere('ends_at','>',now()))->with(['vendor','scopes'])->orderByDesc('priority')->limit(50)->get();
        $items=[];
        foreach($promos as $promo){$q=Product::query()->published()->with(['vendor','category','images.mediaAsset','variants.inventories']);if($promo->vendor_id)$q->where('vendor_id',$promo->vendor_id);$scopes=$promo->scopes;if($scopes->isNotEmpty()&&!$scopes->contains(/** Inline callback for this operation. */ fn($s)=>$s->scope_type==='all')){$products=$scopes->where('scope_type','product')->pluck('product_id')->filter()->all();$categories=$scopes->where('scope_type','category')->pluck('category_id')->filter()->all();$q->where(/** Inline callback for this operation. */ fn($x)=>$x->when($products,/** Inline callback for this operation. */ fn($z)=>$z->orWhereIn('id',$products))->when($categories,/** Inline callback for this operation. */ fn($z)=>$z->orWhereIn('category_id',$categories)));}$rows=$q->limit(8)->get();foreach($rows as $product){$base=(int)$product->base_price_minor;$discount=$promo->discount_type==='percent'?intdiv($base*(int)$promo->percent_bps,10000):min($base,(int)$promo->fixed_minor);$items[]=['promotion'=>['id'=>$promo->public_id,'name'=>$promo->name,'kind'=>$promo->kind,'endsAt'=>$promo->ends_at?->toISOString(),'fundingMode'=>$promo->funding_mode],'product'=>(new ProductResource($product))->resolve($request),'dealPriceMinor'=>max(0,$base-$discount),'discountMinor'=>$discount];if(count($items)>=$limit)break 2;}}
        return ['items'=>$items,'generatedAt'=>now()->toISOString()];
    }
}
