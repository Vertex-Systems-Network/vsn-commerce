<?php
namespace App\Domain\Returns\Actions;
use App\Domain\Payments\Actions\RefundOrderPayment;
use App\Domain\Finance\Actions\PostRefundFinance;
use App\Domain\Returns\Exceptions\ReturnException;
use App\Domain\Returns\Services\RefundCalculator;
use App\Domain\Wallet\Actions\CreditWalletRefund;
use App\Domain\Reviews\Actions\ReconcileReviewCouponsAfterRefund;
use App\Domain\Tax\Actions\IssueRefundCreditNotes;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\RefundStatus;
use App\Enums\ReturnRequestStatus;
use App\Enums\ReturnResolution;
use App\Models\Refund;
use App\Models\RefundEvent;
use App\Models\ReturnRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
/** Defines the ProcessRefund class and its project responsibilities. */
class ProcessRefund
{
    /** Initializes the ProcessRefund instance and its dependencies. */
    public function __construct(private readonly RefundCalculator $calculator,private readonly RefundOrderPayment $payments,private readonly CreditWalletRefund $wallet,private readonly ApplyVendorRefundAdjustments $vendors,private readonly ReverseAffiliateForRefund $affiliate,private readonly ReconcileReviewCouponsAfterRefund $reviewCoupons,private readonly PostRefundFinance $finance,private readonly IssueRefundCreditNotes $creditNotes) {}
    /** Executes the process refund operation. */
    public function execute(ReturnRequest $request): Refund
    {
        if(!in_array($request->resolution,[ReturnResolution::OriginalPayment,ReturnResolution::Coins],true))throw new ReturnException('This return resolution does not create a refund.');
        $existing=Refund::query()->where('return_request_id',$request->id)->first();
        if($existing && in_array($existing->status,[RefundStatus::Completed,RefundStatus::ManualPaymentRequired],true))return $existing->load(['paymentTransaction','walletTransaction','vendorAdjustments','events']);
        $refund=DB::transaction(/** Inline callback for this operation. */ function() use($request): Refund {
            $request=ReturnRequest::query()->whereKey($request->id)->with(['order','items.orderItem'])->lockForUpdate()->firstOrFail();
            if(!in_array($request->status,[ReturnRequestStatus::Received,ReturnRequestStatus::Approved],true))throw new ReturnException('Return must be approved/received before refund processing.');
            $amount=(int)$request->approved_minor; if($amount<=0)throw new ReturnException('Approved refund amount is zero.');
            $order=$request->order;$refundableTaxAdded=(int)$order->items()->sum('tax_added_minor');
            if($amount>max(0,((int)$order->subtotal_minor+$refundableTaxAdded-(int)$order->discount_minor)-(int)$order->refunded_minor))throw new ReturnException('Refund exceeds remaining refundable merchandise value.');
            $split=$this->calculator->tenderSplit($order,$amount,$request->resolution===ReturnResolution::Coins);
            $taxRefund=(int)$request->items->sum(/** Inline callback for this operation. */ function($ri)use($request){$qty=$this->effectiveQuantity($request,$ri);return intdiv((int)$ri->orderItem->tax_minor*$qty,max(1,(int)$ri->orderItem->quantity));});
            return Refund::query()->firstOrCreate(['return_request_id'=>$request->id],[
                'public_id'=>(string)Str::ulid(),'order_id'=>$order->id,'status'=>RefundStatus::Processing,'resolution'=>$request->resolution,'currency'=>$order->currency,'amount_minor'=>$amount,'tax_refund_minor'=>$taxRefund,
                'cash_refund_minor'=>$split['cashMinor'],'coin_refund_minor'=>$split['coinMinor'],'coin_refund_coins'=>$split['coinCoins'],'idempotency_key'=>"return-refund:{$request->public_id}",
            ]);
        },3);
        $refund->increment('attempt_count');$refund->update(['last_attempt_at'=>now(),'status'=>RefundStatus::Processing]);
        $this->event($refund,'attempt_started',null,'Refund processing attempt started.',['attempt'=>(int)$refund->fresh()->attempt_count]);
        try{
            $order=$refund->order()->with('user')->firstOrFail();
            if($refund->cash_refund_minor>0 && $order->payment_method==='cod'){
                $refund->update(['status'=>RefundStatus::ManualPaymentRequired,'metadata'=>array_merge($refund->metadata??[],['manual_reason'=>'COD cash refund requires finance confirmation'])]);
                $this->event($refund,'manual_payment_required',null,'COD cash refund requires finance confirmation.');
                return $refund->fresh()->load(['paymentTransaction','walletTransaction','vendorAdjustments','events']);
            }
            $paymentTx=null;$walletTx=null;
            if($refund->cash_refund_minor>0){
                $paymentTx=$this->payments->execute($order,(int)$refund->cash_refund_minor,"payment-refund:{$refund->public_id}",$refund->public_id);
                $refund->update(['payment_refund_transaction_id'=>$paymentTx?->id]);
                if($paymentTx && $paymentTx->status===PaymentTransactionStatus::Pending){
                    $refund->update(['status'=>RefundStatus::Processing,'metadata'=>array_merge($refund->metadata??[],['provider_refund_status'=>'pending'])]);
                    $this->event($refund,'provider_pending',$paymentTx->provider_transaction_id,'Payment provider refund is pending.');
                    return $refund->fresh()->load(['paymentTransaction','walletTransaction','vendorAdjustments','events']);
                }
                if($paymentTx && $paymentTx->status!==PaymentTransactionStatus::Succeeded){
                    $refund->update(['status'=>RefundStatus::NeedsReview,'metadata'=>array_merge($refund->metadata??[],['provider_refund_status'=>$paymentTx->status->value])]);
                    $this->event($refund,'provider_failed',$paymentTx->provider_transaction_id,'Payment provider refund did not succeed.');
                    return $refund->fresh()->load(['paymentTransaction','walletTransaction','vendorAdjustments','events']);
                }
            }
            if($refund->coin_refund_coins>0)$walletTx=$this->wallet->execute($order->user,(int)$refund->coin_refund_coins,'refund',$refund->public_id,"wallet-refund:{$refund->public_id}",['amount_minor'=>$refund->coin_refund_minor]);
            $refund->update(['wallet_refund_transaction_id'=>$walletTx?->id]);
            $this->finalize($refund);
        }catch(\Throwable $e){
            $refund->update(['status'=>RefundStatus::NeedsReview,'metadata'=>array_merge($refund->metadata??[],['error'=>$e->getMessage()])]);
            $this->event($refund,'attempt_failed',null,$e->getMessage());
            throw $e;
        }
        return $refund->fresh()->load(['paymentTransaction','walletTransaction','vendorAdjustments','events']);
    }

    /** Handles confirm manual for the process refund workflow. */
    public function confirmManual(Refund $refund, string $reference, ?int $actorUserId=null, ?string $note=null): Refund
    {
        if($refund->status!==RefundStatus::ManualPaymentRequired) throw new ReturnException('Refund is not waiting for a manual payment confirmation.');
        if(trim($reference)==='')throw new ReturnException('Manual refund reference is required.','reference');
        $order=$refund->order()->with('user')->firstOrFail();$walletTx=null;
        if($refund->coin_refund_coins>0)$walletTx=$this->wallet->execute($order->user,(int)$refund->coin_refund_coins,'refund',$refund->public_id,"wallet-refund:{$refund->public_id}",['amount_minor'=>$refund->coin_refund_minor]);
        $refund->update(['wallet_refund_transaction_id'=>$walletTx?->id,'manual_reference'=>trim($reference),'metadata'=>array_merge($refund->metadata??[],['manual_payment_confirmed_at'=>now()->toIso8601String(),'manual_note'=>$note])]);
        $this->event($refund,'manual_payment_confirmed',trim($reference),'Manual refund payment confirmed.',['actorUserId'=>$actorUserId],$actorUserId);
        $this->finalize($refund);
        return $refund->fresh()->load(['paymentTransaction','walletTransaction','vendorAdjustments','events']);
    }

    /** Handles finalize for the process refund workflow. */
    private function finalize(Refund $refund): void
    {
        if($refund->status===RefundStatus::Completed) return;
        $this->vendors->execute($refund);$this->finance->execute($refund);$this->affiliate->execute($refund);
        DB::transaction(/** Inline callback for this operation. */ function() use($refund): void {
            $refund=Refund::query()->whereKey($refund->id)->with(['order','request.items.orderItem'])->lockForUpdate()->firstOrFail();
            if($refund->status===RefundStatus::Completed)return;
            $order=\App\Models\Order::query()->whereKey($refund->order_id)->lockForUpdate()->firstOrFail();
            $refund->update(['status'=>RefundStatus::Completed,'processed_at'=>now()]);
            $order->increment('refunded_minor',(int)$refund->amount_minor);$order->increment('tax_refunded_minor',(int)$refund->tax_refund_minor);$order->increment('cash_refunded_minor',(int)$refund->cash_refund_minor);$order->increment('coin_refunded_coins',(int)$refund->coin_refund_coins);
            $order=$order->fresh();$refundableTaxAdded=(int)$order->items()->sum('tax_added_minor');$fully=(int)$order->refunded_minor>=max(0,(int)$order->subtotal_minor+$refundableTaxAdded-(int)$order->discount_minor);
            $coinRefundMinor=intdiv((int)$order->coin_refunded_coins,(int)config('vsn.coins_per_rupee',70))*100;
            $paymentFully=((int)$order->cash_refunded_minor+$coinRefundMinor)>=((int)$order->total_minor+(int)$order->coin_redemption_minor);
            $order->update(['status'=>$fully?OrderStatus::Refunded:OrderStatus::PartiallyRefunded,'payment_status'=>$paymentFully?PaymentStatus::Refunded:PaymentStatus::PartiallyRefunded]);
            $refund->request()->update(['status'=>ReturnRequestStatus::Refunded,'resolved_at'=>now()]);
            foreach($refund->request->items as $ri){$qty=$this->effectiveQuantity($refund->request,$ri);if($qty>0)$ri->orderItem()->increment('refunded_quantity',$qty);}
        },3);
        $fresh=$refund->fresh()->load('request.items.orderItem');$this->reviewCoupons->execute($fresh);
        try{$this->creditNotes->execute($fresh);}catch(\Throwable $e){\Illuminate\Support\Facades\Log::error('Tax credit note issuance failed; safe to retry.',['refund'=>$fresh->public_id,'error'=>$e->getMessage()]);}
        $this->event($fresh,'completed',null,'Refund completed and accounting reversals posted.');
    }

    /** Handles effective quantity for the process refund workflow. */
    private function effectiveQuantity(ReturnRequest $request, $row): int
    {
        if($request->inspection_completed_at)return max(0,(int)$row->accepted_quantity);
        return max(0,(int)($row->approved_quantity ?: $row->quantity));
    }
    /** Handles event for the process refund workflow. */
    private function event(Refund $refund,string $event,?string $reference=null,?string $message=null,array $metadata=[],?int $actorUserId=null):void
    {
        RefundEvent::create(['refund_id'=>$refund->id,'actor_user_id'=>$actorUserId,'event'=>$event,'reference'=>$reference,'message'=>$message,'metadata'=>$metadata?:null,'occurred_at'=>now()]);
    }
}
