<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Defines the OrderController class and its project responsibilities. */
class OrderController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = Order::query()
            ->where('user_id', $request->user()->id)
            ->with(['items', 'vendorOrders.vendor', 'vendorOrders.items', 'vendorOrders.shipments', 'shipments.vendor', 'shippingAddress', 'paymentIntents', 'returnRequests.refund', 'gift.sender', 'gift.recipient', 'gift.product.images', 'gift.variant'])
            ->latest('placed_at')
            ->paginate(20);

        return OrderResource::collection($orders);
    }

    /** Handles the show request for this resource. */
    public function show(Request $request, Order $order): OrderResource
    {
        abort_unless($order->user_id === $request->user()->id, 404);

        return new OrderResource($order->load(['items', 'vendorOrders.vendor', 'vendorOrders.items', 'vendorOrders.shipments', 'shipments.vendor', 'shippingAddress', 'paymentIntents', 'returnRequests.refund', 'gift.sender', 'gift.recipient', 'gift.product.images', 'gift.variant']));
    }
}
