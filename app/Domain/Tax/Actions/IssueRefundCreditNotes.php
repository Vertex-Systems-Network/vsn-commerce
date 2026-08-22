<?php
namespace App\Domain\Tax\Actions;
use App\Domain\Tax\Services\TaxDocumentNumberService;
use App\Models\Refund;
use App\Models\TaxCreditNote;
use App\Models\TaxInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
/** Defines the IssueRefundCreditNotes class and its project responsibilities. */
class IssueRefundCreditNotes
{
    /** Initializes the IssueRefundCreditNotes instance and its dependencies. */
    public function __construct(private readonly TaxDocumentNumberService $numbers){}
    /** Executes the issue refund credit notes operation. */
    public function execute(Refund $refund):void
    {
        $refund->load(['request.items.orderItem.vendorOrder','vendorAdjustments.vendorOrder']);
        $request=$refund->request;
        $groups=$request->items->filter(/** Inline callback for this operation. */ fn($r)=>(int)$r->approved_minor>0)->groupBy(/** Inline callback for this operation. */ fn($r)=>$r->orderItem->vendor_order_id);
        foreach($groups as $voId=>$rows){
            $invoice=TaxInvoice::query()->where('vendor_order_id',$voId)->first();
            if(!$invoice||TaxCreditNote::query()->where('refund_id',$refund->id)->where('tax_invoice_id',$invoice->id)->exists())continue;
            $adj=$refund->vendorAdjustments->firstWhere('vendor_order_id',(int)$voId);
            DB::transaction(/** Inline callback for this operation. */ function()use($refund,$request,$invoice,$rows,$adj,$voId){
                if(TaxCreditNote::query()->where('refund_id',$refund->id)->where('tax_invoice_id',$invoice->id)->exists())return;
                $tax=(int)($adj?->tax_reversal_minor??0);$total=(int)$rows->sum('approved_minor');$subtotal=max(0,$total-$tax);
                $cn=TaxCreditNote::create(['public_id'=>(string)Str::ulid(),'credit_note_number'=>$this->numbers->next((string)config('vsn.tax.credit_note_prefix','CN'),$invoice->vendor_id),'refund_id'=>$refund->id,'tax_invoice_id'=>$invoice->id,'vendor_order_id'=>$voId,'currency'=>$refund->currency,'subtotal_minor'=>$subtotal,'tax_minor'=>$tax,'total_minor'=>$total,'issued_at'=>now(),'metadata'=>['refund'=>$refund->public_id,'invoice'=>$invoice->invoice_number]]);
                foreach($rows as $r){
                    $item=$r->orderItem;$ordered=max(1,(int)$item->quantity);$qty=$request->inspection_completed_at?max(0,(int)$r->accepted_quantity):max(0,(int)($r->approved_quantity?:$r->quantity));
                    if($qty<=0)continue;
                    $itemTax=intdiv((int)$item->tax_minor*$qty,$ordered);$line=(int)$r->approved_minor;
                    $cn->items()->create(['order_item_id'=>$item->id,'description'=>$item->product_name.' — '.$item->variant_name,'quantity'=>$qty,'subtotal_minor'=>max(0,$line-$itemTax),'tax_minor'=>$itemTax,'total_minor'=>$line]);
                }
            },3);
        }
    }
}
