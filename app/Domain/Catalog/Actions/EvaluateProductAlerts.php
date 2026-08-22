<?php
namespace App\Domain\Catalog\Actions;
use App\Domain\Notifications\Actions\PublishMarketplaceNotification;
use App\Enums\ProductAlertStatus;
use App\Enums\ProductAlertType;
use App\Models\Product;
use App\Models\ProductAlert;
use Illuminate\Support\Facades\DB;
/** Defines the EvaluateProductAlerts class and its project responsibilities. */
class EvaluateProductAlerts
{
    /** Initializes the EvaluateProductAlerts instance and its dependencies. */
    public function __construct(private readonly PublishMarketplaceNotification $publish){}
    /** Executes the evaluate product alerts operation. */
    public function execute(?Product $product=null,int $limit=500):array
    {
        $q=ProductAlert::query()->where('status',ProductAlertStatus::Active->value)->with(['user','product.images','variant.inventories','product.variants.inventories']);if($product)$q->where('product_id',$product->id);
        $checked=0;$triggered=0;
        $q->orderBy('id')->limit($limit)->get()->each(/** Inline callback for this operation. */ function(ProductAlert $alert)use(&$checked,&$triggered){
            $did=DB::transaction(/** Inline callback for this operation. */ function()use($alert,&$checked){$row=ProductAlert::query()->whereKey($alert->id)->lockForUpdate()->with(['user','product.images','variant.inventories','product.variants.inventories'])->first();if(!$row||$row->status!==ProductAlertStatus::Active)return false;$checked++;
                $product=$row->product;if(!$product||$product->status->value!=='published'){$row->update(['last_checked_at'=>now()]);return false;}
                $price=(int)($row->variant?->price_minor??$product->base_price_minor);$stock=$row->variant?(int)$row->variant->inventories->sum(/** Inline callback for this operation. */ fn($i)=>$i->available()):(int)$product->variants->where('is_active',true)->sum(/** Inline callback for this operation. */ fn($v)=>$v->inventories->sum(/** Inline callback for this operation. */ fn($i)=>$i->available()));
                $should=false;if($row->type===ProductAlertType::PriceDrop){$threshold=$row->target_price_minor;$should=$price<(int)($row->last_observed_price_minor??$price+1)&&($threshold===null||$price<=$threshold)&&$price!==$row->last_notified_price_minor;}else{$should=(int)$row->last_observed_stock<=0&&$stock>0;}
                $row->update(['last_observed_price_minor'=>$price,'last_observed_stock'=>$stock,'last_checked_at'=>now(),...($should?['last_notified_price_minor'=>$price,'last_notified_stock'=>$stock,'triggered_at'=>now(),'status'=>$row->type===ProductAlertType::BackInStock?ProductAlertStatus::Triggered->value:ProductAlertStatus::Active->value]:[])]);
                if($should&&$row->user){$type=$row->type===ProductAlertType::PriceDrop?'product.price_drop':'product.back_in_stock';$title=$row->type===ProductAlertType::PriceDrop?'Price dropped':'Back in stock';$body=$row->type===ProductAlertType::PriceDrop?"{$product->name} is now Rs. ".number_format($price/100,2).'.':"{$product->name} is back in stock.";$this->publish->execute($row->user,'rewards',$type,$title,$body,"product-alert:{$row->public_id}:{$type}:{$price}:{$stock}",'/product/'.$product->slug,'product_alert',$row->public_id,['productId'=>$product->public_id,'productSlug'=>$product->slug,'priceMinor'=>$price,'stock'=>$stock]);}
                return $should;},3);if($did)$triggered++;});
        return ['checked'=>$checked,'triggered'=>$triggered];
    }
}
