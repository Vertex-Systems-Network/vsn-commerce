<?php
namespace App\Domain\Shipping\Actions;
use App\Domain\Finance\Actions\ReconcileVendorSettlements;
use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
/** Defines the ReconcileOrderFulfillment class and its project responsibilities. */
class ReconcileOrderFulfillment
{
    /** Initializes the ReconcileOrderFulfillment instance and its dependencies. */
    public function __construct(private readonly ReconcileVendorSettlements $settlements){}
    /** Executes the reconcile order fulfillment operation. */
    public function execute(Order $order):Order
    {
        $vendors=[];
        $order=DB::transaction(/** Inline callback for this operation. */ function()use($order,&$vendors):Order{
            $o=Order::query()->whereKey($order->id)->lockForUpdate()->with('vendorOrders')->firstOrFail();
            if(in_array($o->status,[OrderStatus::Cancelled,OrderStatus::Returned,OrderStatus::Refunded,OrderStatus::PartiallyRefunded],true))return $o;
            $states=$o->vendorOrders->pluck('status');
            if($states->isEmpty())return $o;
            $status=OrderStatus::Processing;
            $deliveredAt=$o->delivered_at;
            if($states->every(/** Inline callback for this operation. */ fn($s)=>$s===OrderStatus::Delivered)){
                $status=OrderStatus::Delivered;
                $latest=$o->vendorOrders->pluck('delivered_at')->filter()->sortDesc()->first();
                $deliveredAt=$deliveredAt??$latest??now();
            }elseif($states->contains(OrderStatus::OutForDelivery))$status=OrderStatus::OutForDelivery;
            elseif($states->contains(/** Inline callback for this operation. */ fn($s)=>in_array($s,[OrderStatus::Shipped,OrderStatus::Delivered],true)))$status=OrderStatus::Shipped;
            elseif($states->contains(OrderStatus::Packed))$status=OrderStatus::Packed;
            $o->update(['status'=>$status,'delivered_at'=>$deliveredAt]);
            $vendors=$o->vendorOrders->whereNotNull('delivered_at')->pluck('vendor_id')->filter()->unique()->values()->all();
            return $o->fresh();
        },3);
        foreach($vendors as $vendorId)$this->settlements->execute((int)$vendorId);
        return $order;
    }
}
