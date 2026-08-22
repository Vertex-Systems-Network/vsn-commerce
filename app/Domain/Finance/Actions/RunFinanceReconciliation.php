<?php
namespace App\Domain\Finance\Actions;
use App\Models\FinanceEntry;
use App\Models\FinanceJournal;
use App\Models\FinanceReconciliationRun;
use App\Models\Order;
use App\Models\User;
use App\Models\VendorPayout;
use App\Models\VendorSettlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
/** Defines the RunFinanceReconciliation class and its project responsibilities. */
class RunFinanceReconciliation
{
    /** Initializes the RunFinanceReconciliation instance and its dependencies. */
    public function __construct(private readonly PostOrderFinance $postOrders,private readonly ReconcileVendorSettlements $settlements){}
    /** Executes the run finance reconciliation operation. */
    public function execute(?User $actor=null,bool $repair=true):FinanceReconciliationRun
    {
        $run=FinanceReconciliationRun::create(['public_id'=>(string)Str::ulid(),'started_by_user_id'=>$actor?->id,'status'=>'running','started_at'=>now()]);$issues=0;
        if($repair){Order::query()->whereHas('vendorOrders',/** Inline callback for this operation. */ fn($q)=>$q->whereNull('finance_posted_at'))->with('vendorOrders')->orderBy('id')->chunkById(100,/** Inline callback for this operation. */ function($orders):void{foreach($orders as $order)$this->postOrders->execute($order);});$this->settlements->execute();}
        FinanceJournal::query()->with('entries')->orderBy('id')->chunkById(100,/** Inline callback for this operation. */ function($rows)use($run,&$issues):void{foreach($rows as $j){$d=(int)$j->entries->where('direction','debit')->sum('amount_minor');$c=(int)$j->entries->where('direction','credit')->sum('amount_minor');if($d!==$c){$run->issues()->create(['code'=>'unbalanced_journal','reference_type'=>'finance_journal','reference_id'=>$j->public_id,'expected_minor'=>$d,'actual_minor'=>$c,'delta_minor'=>$d-$c,'message'=>'Finance journal debits and credits do not balance.']);$issues++;}}});
        VendorSettlement::query()->with('vendorOrder')->orderBy('id')->chunkById(100,/** Inline callback for this operation. */ function($rows)use($run,&$issues):void{foreach($rows as $s){$vo=$s->vendorOrder;if(!$vo)continue;foreach(['seller_payable_minor','seller_payable_reversed_minor','seller_recovery_offset_minor','payout_reserved_minor','paid_out_minor'] as $field){$expected=(int)$vo->{$field};$actual=(int)$s->{$field};if($expected!==$actual){$run->issues()->create(['code'=>'settlement_cache_mismatch','reference_type'=>'vendor_order','reference_id'=>$vo->public_id,'expected_minor'=>$expected,'actual_minor'=>$actual,'delta_minor'=>$actual-$expected,'message'=>"Vendor settlement {$field} differs from vendor order cache.",'metadata'=>['field'=>$field]]);$issues++;}}}});
        VendorPayout::query()->where('status','paid')->doesntHave('items')->each(/** Inline callback for this operation. */ function($p)use($run,&$issues):void{$run->issues()->create(['code'=>'paid_payout_without_items','reference_type'=>'vendor_payout','reference_id'=>$p->public_id,'expected_minor'=>$p->amount_minor,'actual_minor'=>0,'delta_minor'=>-$p->amount_minor,'message'=>'Paid payout has no settlement allocations.']);$issues++;});
        $run->update(['status'=>$issues?'issues':'clean','completed_at'=>now(),'issues_count'=>$issues,'summary'=>['journals'=>FinanceJournal::count(),'entries'=>FinanceEntry::count(),'settlements'=>VendorSettlement::count(),'payouts'=>VendorPayout::count()]]);return $run->fresh('issues');
    }
}
