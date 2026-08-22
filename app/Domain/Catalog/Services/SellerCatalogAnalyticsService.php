<?php
namespace App\Domain\Catalog\Services;
use App\Enums\PaymentStatus;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductView;
use App\Models\Vendor;
use App\Models\WishlistItem;
/** Defines the SellerCatalogAnalyticsService class and its project responsibilities. */
class SellerCatalogAnalyticsService
{
    /** Executes the seller catalog analytics service operation. */
    public function execute(Vendor $vendor,int $days=30):array
    {
        $days=min(90,max(7,$days));$from=now()->subDays($days-1)->startOfDay();$productIds=Product::query()->where('vendor_id',$vendor->id)->pluck('id');
        $views=ProductView::query()->whereIn('product_id',$productIds)->where('viewed_at','>=',$from)->where(/** Inline callback for this operation. */ fn($q)=>$q->whereNull('user_id')->orWhere('user_id','!=',$vendor->owner_user_id))->get(['product_id','user_id','visitor_hash','viewed_at']);
        $wishCount=WishlistItem::query()->whereIn('product_id',$productIds)->where('user_id','!=',$vendor->owner_user_id)->count();
        $orders=OrderItem::query()->whereIn('product_id',$productIds)->whereHas('order',/** Inline callback for this operation. */ fn($q)=>$q->whereIn('payment_status',[PaymentStatus::Paid->value,PaymentStatus::PartiallyRefunded->value])->where('placed_at','>=',$from))->with('order:id,placed_at,user_id')->get(['id','order_id','product_id','quantity','refunded_quantity','line_total_minor']);
        $units=$orders->sum(/** Inline callback for this operation. */ fn($x)=>max(0,$x->quantity-$x->refunded_quantity));$revenue=$orders->sum(/** Inline callback for this operation. */ function($x){$net=max(0,$x->quantity-$x->refunded_quantity);return $x->quantity>0?(int)round($x->line_total_minor*($net/$x->quantity)):0;});$buyers=$orders->pluck('order.user_id')->filter()->unique()->count();$uniqueVisitors=$views->map(/** Inline callback for this operation. */ fn($v)=>$v->user_id?'u:'.$v->user_id:'g:'.$v->visitor_hash)->filter(/** Inline callback for this operation. */ fn($x)=>!str_ends_with($x,'g:'))->unique()->count();
        $products=Product::query()->where('vendor_id',$vendor->id)->get(['id','public_id','name','slug','sold_count'])->map(/** Inline callback for this operation. */ function($p)use($views,$orders){$pv=$views->where('product_id',$p->id)->count();$po=$orders->where('product_id',$p->id);$u=$po->sum(/** Inline callback for this operation. */ fn($x)=>max(0,$x->quantity-$x->refunded_quantity));$r=$po->sum('line_total_minor');return ['id'=>$p->public_id,'slug'=>$p->slug,'name'=>$p->name,'views'=>$pv,'units'=>$u,'revenueMinor'=>(int)$r,'conversionPercent'=>$pv>0?round(($po->pluck('order_id')->unique()->count()/$pv)*100,2):0];})->sortByDesc('revenueMinor')->take(20)->values()->all();
        $trend=[];for($i=$days-1;$i>=0;$i--){$date=now()->subDays($i)->toDateString();$dv=$views->filter(/** Inline callback for this operation. */ fn($x)=>$x->viewed_at?->toDateString()===$date)->count();$di=$orders->filter(/** Inline callback for this operation. */ fn($x)=>$x->order?->placed_at?->toDateString()===$date);$trend[]=['date'=>$date,'views'=>$dv,'orders'=>$di->pluck('order_id')->unique()->count(),'revenueMinor'=>(int)$di->sum('line_total_minor')];}
        $orderCount=$orders->pluck('order_id')->unique()->count();return ['windowDays'=>$days,'summary'=>['views'=>$views->count(),'uniqueVisitors'=>$uniqueVisitors,'wishlistSaves'=>$wishCount,'orders'=>$orderCount,'units'=>$units,'revenueMinor'=>$revenue,'buyers'=>$buyers,'conversionPercent'=>$views->count()>0?round(($orderCount/$views->count())*100,2):0],'products'=>$products,'trend'=>$trend];
    }
}
