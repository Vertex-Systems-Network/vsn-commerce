<?php
namespace App\Domain\Returns\Actions;
use App\Models\Refund;
use App\Models\VendorOrder;
use App\Models\VendorRefundAdjustment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
/** Defines the ApplyVendorRefundAdjustments class and its project responsibilities. */
class ApplyVendorRefundAdjustments
{
    /** Executes the apply vendor refund adjustments operation. */
    public function execute(Refund $refund): void
    {
        $request=$refund->request()->with('items.orderItem')->firstOrFail();
        $groups=$request->items->filter(/** Inline callback for this operation. */ fn($r)=>(int)$r->approved_minor>0)->groupBy(/** Inline callback for this operation. */ fn($r)=>$r->orderItem->vendor_order_id);
        foreach($groups as $vendorOrderId=>$rows){
            DB::transaction(/** Inline callback for this operation. */ function() use($refund,$vendorOrderId,$rows): void {
                if(VendorRefundAdjustment::query()->where('refund_id',$refund->id)->where('vendor_order_id',$vendorOrderId)->exists())return;
                $vendor=VendorOrder::query()->whereKey($vendorOrderId)->lockForUpdate()->firstOrFail();
                if(!$vendor->finance_posted_at){$sellerDiscount=(int)$vendor->seller_discount_minor;$legacy=max(0,(int)$vendor->subtotal_minor+(int)$vendor->shipping_minor-$sellerDiscount-(int)$vendor->platform_commission_minor);$expected=(int)$vendor->seller_payable_minor>0?(int)$vendor->seller_payable_minor:$legacy;$vendor->update(['seller_payable_minor'=>$expected,'coupon_subsidy_minor'=>max(0,(int)$vendor->discount_minor-$sellerDiscount)]);$vendor->refresh();}
                $request=$refund->request()->firstOrFail();
                $effectiveQty=/** Inline callback for this operation. */ fn($r)=>$request->inspection_completed_at?max(0,(int)$r->accepted_quantity):max(0,(int)($r->approved_quantity?:$r->quantity));
                $refundMinor=(int)$rows->sum('approved_minor');$gross=(int)$rows->sum(/** Inline callback for this operation. */ fn($r)=>(int)$r->orderItem->unit_price_minor*$effectiveQty($r));
                $sellerDiscount=0;$platformDiscount=0;$tax=0;$platformTax=0;$sellerTax=0;$includedTax=0;
                foreach($rows as $r){$item=$r->orderItem;$qty=max(1,(int)$item->quantity);$returned=$effectiveQty($r);$sellerDiscount+=intdiv((int)($item->metadata['seller_discount_minor']??0)*$returned,$qty);$platformDiscount+=intdiv((int)($item->metadata['platform_discount_minor']??0)*$returned,$qty);$tax+=intdiv((int)$item->tax_minor*$returned,$qty);$platformTax+=intdiv((int)$item->platform_tax_minor*$returned,$qty);$sellerTax+=intdiv((int)$item->seller_tax_minor*$returned,$qty);$includedTax+=intdiv((int)$item->tax_included_minor*$returned,$qty);}
                // Legacy fallback: before Milestone R every discount was platform-funded.
                if($sellerDiscount===0&&$platformDiscount===0&&$gross>$refundMinor)$platformDiscount=max(0,$gross-$refundMinor);
                $commissionBase=max(0,$gross-$sellerDiscount-$includedTax);
                $commission=min(max(0,(int)$vendor->platform_commission_minor-(int)$vendor->platform_commission_reversed_minor),intdiv($commissionBase*(int)$vendor->commission_bps,10000));
                $subsidy=min(max(0,(int)$vendor->coupon_subsidy_minor-(int)$vendor->coupon_subsidy_reversed_minor),$platformDiscount);
                $seller=min(max(0,(int)$vendor->seller_payable_minor-(int)$vendor->seller_payable_reversed_minor),max(0,$commissionBase-$commission+$sellerTax));
                VendorRefundAdjustment::create(['public_id'=>(string)Str::ulid(),'refund_id'=>$refund->id,'vendor_order_id'=>$vendor->id,'refund_minor'=>$refundMinor,'seller_discount_reversal_minor'=>$sellerDiscount,'platform_commission_reversal_minor'=>$commission,'seller_payable_reversal_minor'=>$seller,'coupon_subsidy_reversal_minor'=>$subsidy,'tax_reversal_minor'=>$tax,'platform_tax_reversal_minor'=>$platformTax,'seller_tax_reversal_minor'=>$sellerTax,'metadata'=>['platform_discount_reversal_minor'=>$platformDiscount]]);
                $vendor->increment('refunded_minor',$refundMinor);$vendor->increment('platform_commission_reversed_minor',$commission);$vendor->increment('seller_payable_reversed_minor',$seller);$vendor->increment('coupon_subsidy_reversed_minor',$subsidy);$vendor->increment('tax_reversed_minor',$tax);$vendor->increment('platform_tax_reversed_minor',$platformTax);$vendor->increment('seller_tax_reversed_minor',$sellerTax);
            },3);
        }
    }
}
