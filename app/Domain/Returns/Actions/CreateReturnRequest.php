<?php
namespace App\Domain\Returns\Actions;
use App\Domain\Returns\Exceptions\ReturnException;
use App\Domain\Settings\MarketplaceSettings;
use App\Domain\Returns\Services\RefundCalculator;
use App\Domain\Risk\Services\RiskGate;
use App\Domain\Risk\Services\RiskRecorder;
use App\Domain\Risk\Exceptions\RiskBlockedException;
use App\Enums\DisputeStatus;
use App\Enums\OrderStatus;
use App\Enums\ReturnRequestStatus;
use App\Enums\ReturnResolution;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\ReturnRequestItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
/** Defines the CreateReturnRequest class and its project responsibilities. */
class CreateReturnRequest
{
    /** Initializes the CreateReturnRequest instance and its dependencies. */
    public function __construct(private readonly RefundCalculator $calculator, private readonly RiskGate $risk, private readonly RiskRecorder $riskEvents, private readonly MarketplaceSettings $settings) {}
    /** Executes the create return request operation. */
    public function execute(User $user, Order $order, ReturnResolution $resolution, string $reason, ?string $details, array $requestedItems=[]): ReturnRequest
    {
        if($order->user_id!==$user->id) abort(404);
        try { $this->risk->returns($user); }
        catch (RiskBlockedException $e) { throw new ReturnException($e->getMessage(), 'risk'); }
        return DB::transaction(/** Inline callback for this operation. */ function() use($user,$order,$resolution,$reason,$details,$requestedItems): ReturnRequest {
            $order=Order::query()->whereKey($order->id)->with('items')->lockForUpdate()->firstOrFail();
            if(!in_array($order->status,[OrderStatus::Delivered,OrderStatus::PartiallyRefunded],true)) throw new ReturnException('Returns can only be started for delivered orders.','orderId');
            $deliveredAt=$order->delivered_at ?: $order->placed_at; if($deliveredAt && $deliveredAt->copy()->addDays($this->settings->returnsWindowDays())->isPast()) throw new ReturnException('The return window for this order has expired.','orderId');
            $open=ReturnRequest::query()->where('order_id',$order->id)->whereIn('status',[ReturnRequestStatus::Submitted->value,ReturnRequestStatus::Reviewing->value,ReturnRequestStatus::Approved->value,ReturnRequestStatus::InTransit->value,ReturnRequestStatus::Received->value,ReturnRequestStatus::Disputed->value])->exists();
            if($open) throw new ReturnException('An active return or dispute already exists for this order.','orderId');
            $net=$this->calculator->netItemTotals($order); $wanted=collect($requestedItems)->keyBy(/** Inline callback for this operation. */ fn($x)=>(int)($x['orderItemId']??0));
            $rows=[]; $total=0;
            foreach($order->items as $item){
                $prior=(int)ReturnRequestItem::query()->where('order_item_id',$item->id)->whereHas('request',/** Inline callback for this operation. */ fn($q)=>$q->whereNotIn('status',[ReturnRequestStatus::Rejected->value,ReturnRequestStatus::Cancelled->value]))->sum('quantity');
                $remaining=max(0,(int)$item->quantity-$prior); if($remaining===0) continue;
                $qty=$wanted->isEmpty()?$remaining:(int)($wanted->get($item->id)['quantity']??0); if($qty<=0) continue;
                if($qty>$remaining) throw new ReturnException("Return quantity exceeds remaining quantity for {$item->product_name}.",'items');
                $minor=$this->calculator->portion($net[$item->id]??0,(int)$item->quantity,$prior,$qty); $total+=$minor;
                $rows[]=['order_item_id'=>$item->id,'quantity'=>$qty,'requested_minor'=>$minor,'approved_minor'=>0,'restock'=>true];
            }
            if(!$rows) throw new ReturnException('Choose at least one returnable item.','items');
            $status=$resolution===ReturnResolution::Dispute?ReturnRequestStatus::Disputed:ReturnRequestStatus::Submitted;
            $request=ReturnRequest::create(['public_id'=>(string)Str::ulid(),'user_id'=>$user->id,'order_id'=>$order->id,'status'=>$status,'resolution'=>$resolution,'reason'=>$reason,'details'=>$details,'currency'=>$order->currency,'requested_minor'=>$total,'submitted_at'=>now()]);
            foreach($rows as $row)$request->items()->create($row);
            if($resolution===ReturnResolution::Dispute){
                $this->riskEvents->record($user, null, 'marketplace_dispute_opened', 'medium', 8, 'returns', 'return_request', $request->public_id, 'risk-dispute:'.$request->public_id, ['orderId'=>$order->public_id,'requestedMinor'=>$total]);
                Dispute::create(['public_id'=>(string)Str::ulid(),'return_request_id'=>$request->id,'order_id'=>$order->id,'opened_by_user_id'=>$user->id,'status'=>DisputeStatus::Open,'opened_at'=>now()]);
            }
            return $request->load(['items.orderItem','order','refund','dispute']);
        },3);
    }
}
