<?php
namespace App\Domain\Catalog\Actions;
use App\Enums\ProductAlertStatus;
use App\Enums\ProductAlertType;
use App\Models\Product;
use App\Models\ProductAlert;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
/** Defines the CreateProductAlert class and its project responsibilities. */
class CreateProductAlert
{
    /** Executes the create product alert operation. */
    public function execute(User $user,Product $product,ProductAlertType $type,?ProductVariant $variant=null,?int $targetPriceMinor=null):ProductAlert
    {
        abort_unless($product->status->value==='published',404);
        if($variant)abort_unless($variant->product_id===$product->id&&$variant->is_active,422,'Variant does not belong to this product.');
        $price=(int)($variant?->price_minor??$product->base_price_minor);$stock=$this->stock($product,$variant);
        if($type===ProductAlertType::PriceDrop&&$targetPriceMinor!==null&&$targetPriceMinor>=$price)abort(422,'Target price must be below the current price.');
        if($type===ProductAlertType::BackInStock&&$stock>0)abort(422,'This product is already in stock.');
        return DB::transaction(/** Inline callback for this operation. */ function()use($user,$product,$variant,$type,$targetPriceMinor,$price,$stock){
            return ProductAlert::query()->updateOrCreate(
                ['user_id'=>$user->id,'product_id'=>$product->id,'product_variant_id'=>$variant?->id,'type'=>$type->value,'scope_key'=>$variant?'variant:'.$variant->id:'product'],
                ['status'=>ProductAlertStatus::Active->value,'target_price_minor'=>$type===ProductAlertType::PriceDrop?$targetPriceMinor:null,'last_observed_price_minor'=>$price,'last_observed_stock'=>$stock,'last_notified_price_minor'=>null,'last_notified_stock'=>null,'triggered_at'=>null,'last_checked_at'=>now()]
            );
        },3);
    }
    /** Handles stock for the create product alert workflow. */
    private function stock(Product $product,?ProductVariant $variant):int
    { if($variant)return (int)$variant->inventories()->get()->sum(/** Inline callback for this operation. */ fn($i)=>$i->available()); return (int)$product->variants()->where('is_active',true)->with('inventories')->get()->sum(/** Inline callback for this operation. */ fn($v)=>$v->inventories->sum(/** Inline callback for this operation. */ fn($i)=>$i->available())); }
}
