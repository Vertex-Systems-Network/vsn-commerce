<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Defines the AdminOrderController class and its project responsibilities. */
class AdminOrderController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(Request $request): JsonResponse
    {
        $this->viewer($request);
        $data = $request->validate([
            'q' => ['nullable','string','max:120'],
            'status' => ['nullable','string'],
            'paymentStatus' => ['nullable','string'],
            'perPage' => ['nullable','integer','min:10','max:100'],
        ]);
        $query = Order::query()->with(['user','items','vendorOrders.vendor','vendorOrders.items','vendorOrders.shipments','shipments.vendor','shippingAddress','paymentIntents','returnRequests.refund']);
        if (!empty($data['q'])) {
            $q = trim($data['q']);
            $query->where(/** Inline callback for this operation. */ function ($builder) use ($q): void {
                $builder->where('public_id','like',"%{$q}%")
                    ->orWhereHas('user', /** Inline callback for this operation. */ fn($u) => $u->where('name','like',"%{$q}%")->orWhere('email','like',"%{$q}%"));
            });
        }
        if (!empty($data['status'])) $query->where('status',$data['status']);
        if (!empty($data['paymentStatus'])) $query->where('payment_status',$data['paymentStatus']);
        $rows = $query->latest('placed_at')->paginate((int)($data['perPage'] ?? 30));
        return response()->json(['data'=>[
            'items'=>$rows->getCollection()->map(/** Inline callback for this operation. */ fn(Order $order) => $this->summary($order))->values(),
            'meta'=>['total'=>$rows->total(),'currentPage'=>$rows->currentPage(),'lastPage'=>$rows->lastPage(),'perPage'=>$rows->perPage()],
        ]]);
    }

    /** Handles the show request for this resource. */
    public function show(Request $request, Order $order): JsonResponse
    {
        $this->viewer($request);
        $order->load(['user','items','vendorOrders.vendor','vendorOrders.items','vendorOrders.shipments','shipments.vendor','shipments.events','shippingAddress','paymentIntents','paymentTransactions','returnRequests.items.orderItem','returnRequests.refund','returnRequests.dispute','taxInvoices']);
        return response()->json(['data'=>[
            'buyer'=>['id'=>$order->user?->id,'name'=>$order->user?->name,'email'=>$order->user?->email],
            'order'=>(new OrderResource($order))->resolve($request),
        ]]);
    }

    /** Handles status for the admin order controller workflow. */
    public function status(Request $request, Order $order): JsonResponse
    {
        $this->operator($request);
        $data = $request->validate(['status'=>['required','in:confirmed,processing']]);
        $status = OrderStatus::from($data['status']);
        if (in_array($order->status, [OrderStatus::Packed, OrderStatus::Shipped, OrderStatus::OutForDelivery, OrderStatus::Delivered, OrderStatus::Returned, OrderStatus::Refunded, OrderStatus::PartiallyRefunded, OrderStatus::Cancelled], true)) {
            return response()->json(['message'=>'Fulfilment/terminal order states are controlled by shipping, returns and refund workflows.'], 422);
        }
        $updates=['status'=>$status];
        if ($status === OrderStatus::Delivered && !$order->delivered_at) $updates['delivered_at']=now();
        if ($status !== OrderStatus::Delivered) $updates['delivered_at']=$order->delivered_at;
        $order->update($updates);
        if (in_array($status,[OrderStatus::Delivered,OrderStatus::Cancelled],true)) {
            $order->vendorOrders()->update(['status'=>$status->value]);
        }
        return response()->json(['data'=>['id'=>$order->public_id,'status'=>$order->fresh()->status->value,'deliveredAt'=>$order->fresh()->delivered_at?->toISOString()]]);
    }

    /** Handles summary for the admin order controller workflow. */
    private function summary(Order $order): array
    {
        return [
            'id'=>$order->public_id,
            'buyer'=>['id'=>$order->user?->id,'name'=>$order->user?->name,'email'=>$order->user?->email],
            'status'=>$order->status->value,'paymentStatus'=>$order->payment_status->value,'paymentMethod'=>$order->payment_method,
            'currency'=>$order->currency,'totalMinor'=>$order->total_minor,'refundedMinor'=>$order->refunded_minor,
            'items'=>$order->items->sum('quantity'),'sellers'=>$order->vendorOrders->count(),'shipments'=>$order->shipments->count(),'returns'=>$order->returnRequests->count(),
            'placedAt'=>$order->placed_at?->toISOString(),'deliveredAt'=>$order->delivered_at?->toISOString(),
        ];
    }

    /** Handles viewer for the admin order controller workflow. */
    private function viewer(Request $request): void
    {
        $role=$request->user()?->role; $value=$role instanceof UserRole?$role->value:(string)$role;
        abort_unless(in_array($value,[UserRole::Support->value,UserRole::Finance->value,UserRole::Admin->value,UserRole::SuperAdmin->value],true),403);
    }

    /** Handles operator for the admin order controller workflow. */
    private function operator(Request $request): void
    {
        $role=$request->user()?->role; $value=$role instanceof UserRole?$role->value:(string)$role;
        abort_unless(in_array($value,[UserRole::Admin->value,UserRole::SuperAdmin->value],true),403);
    }
}
