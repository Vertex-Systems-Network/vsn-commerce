<?php
namespace App\Domain\Catalog\Actions;
use App\Models\Product;
use App\Models\ProductView;
use App\Models\User;
/** Defines the RecordProductView class and its project responsibilities. */
class RecordProductView
{
    /** Executes the record product view operation. */
    public function execute(Product $product,?User $user,?string $deviceId=null,?int $variantId=null,string $source='product_detail'):ProductView
    {
        abort_unless($product->status->value==='published',404);$visitorHash=$deviceId?hash('sha256',$deviceId):null;
        $q=ProductView::query()->where('product_id',$product->id)->where('viewed_at','>=',now()->subMinutes((int)config('vsn.catalog.product_view_dedup_minutes',30)));
        if($user)$q->where('user_id',$user->id);elseif($visitorHash)$q->whereNull('user_id')->where('visitor_hash',$visitorHash);else $q->whereRaw('1=0');
        if($existing=$q->latest('viewed_at')->first())return $existing;
        return ProductView::create(['user_id'=>$user?->id,'visitor_hash'=>$visitorHash,'product_id'=>$product->id,'product_variant_id'=>$variantId,'source'=>$source,'metadata'=>[],'viewed_at'=>now()]);
    }
}
