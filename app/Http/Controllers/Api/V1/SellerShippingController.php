<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Finance\Services\VendorResolver;
use App\Domain\Shipping\Actions\CreateShipment;
use App\Domain\Shipping\Actions\CancelShipment;
use App\Domain\Shipping\Actions\MarkShipmentReady;
use App\Domain\Shipping\Actions\PackVendorOrder;
use App\Domain\Shipping\Actions\ProcessShippingWebhook;
use App\Domain\Shipping\Exceptions\ShippingException;
use App\Domain\Shipping\Services\ShippingSlaService;
use App\Domain\Shipping\Services\ShipmentLifecycleService;
use App\Http\Controllers\Controller;
use App\Http\Resources\ShipmentResource;
use App\Models\Shipment;
use App\Models\VendorOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Defines the SellerShippingController class and its project responsibilities. */
class SellerShippingController extends Controller
{
    /** Initializes the SellerShippingController instance and its dependencies. */
    public function __construct(private readonly VendorResolver $vendors) {}

    /** Handles the index request for this resource. */
    public function index(Request $request, ShippingSlaService $sla): JsonResponse
    {
        $vendor = $this->vendors->forUser($request->user());
        $orders = VendorOrder::query()
            ->where('vendor_id', $vendor->id)
            ->with(['order', 'items', 'shipments.events'])
            ->whereNotIn('status', ['cancelled', 'returned', 'refunded'])
            ->latest()
            ->limit(100)
            ->get();
        $shipments = Shipment::query()
            ->where('vendor_id', $vendor->id)
            ->with(['order', 'vendorOrder.vendor', 'items.orderItem', 'events'])
            ->latest()
            ->limit(100)
            ->get();

        return response()->json(['data' => [
            'metrics' => $sla->vendorMetrics($vendor),
            'orders' => $orders->map(/** Inline callback for this operation. */ function (VendorOrder $order): array {
                $shipment = $order->shipments->sortByDesc('id')->first();
                return [
                    'id' => $order->public_id,
                    'masterOrderId' => $order->order?->public_id,
                    'status' => $order->status->value,
                    'paymentStatus' => $order->order?->payment_status?->value,
                    'paymentMethod' => $order->order?->payment_method,
                    'placedAt' => $order->order?->placed_at?->toISOString(),
                    'packedAt' => $order->packed_at?->toISOString(),
                    'dispatchedAt' => $order->dispatched_at?->toISOString(),
                    'deliveredAt' => $order->delivered_at?->toISOString(),
                    'items' => $order->items->count(),
                    'shipmentId' => $shipment?->public_id,
                ];
            })->values(),
            'shipments' => ShipmentResource::collection($shipments)->resolve($request),
        ]]);
    }

    /** Handles pack for the seller shipping controller workflow. */
    public function pack(Request $request, VendorOrder $vendorOrder, PackVendorOrder $action): JsonResponse
    {
        $vendor = $this->vendors->forUser($request->user());
        abort_unless($vendorOrder->vendor_id === $vendor->id, 404);
        try { $order = $action->execute($vendorOrder); }
        catch (ShippingException $e) { return $this->error($e); }
        return response()->json(['data' => ['id' => $order->public_id, 'status' => $order->status->value, 'packedAt' => $order->packed_at?->toISOString()]]);
    }

    /** Handles create for the seller shipping controller workflow. */
    public function create(Request $request, VendorOrder $vendorOrder, CreateShipment $action): ShipmentResource|JsonResponse
    {
        $vendor = $this->vendors->forUser($request->user());
        abort_unless($vendorOrder->vendor_id === $vendor->id, 404);
        $data = $request->validate(['serviceCode' => 'required|string|max:80', 'idempotencyKey' => 'required|string|max:190']);
        try { return new ShipmentResource($action->execute($request->user(), $vendorOrder, $data['serviceCode'], $data['idempotencyKey'])); }
        catch (ShippingException $e) { return $this->error($e); }
    }

    /** Handles ready for the seller shipping controller workflow. */
    public function ready(Request $request, Shipment $shipment, MarkShipmentReady $action): ShipmentResource|JsonResponse
    {
        $vendor = $this->vendors->forUser($request->user());
        abort_unless($shipment->vendor_id === $vendor->id, 404);
        try { return new ShipmentResource($action->execute($shipment)); }
        catch (ShippingException $e) { return $this->error($e); }
    }

    /** Handles sandbox event for the seller shipping controller workflow. */
    public function sandboxEvent(Request $request, Shipment $shipment, ProcessShippingWebhook $process): ShipmentResource
    {
        $vendor = $this->vendors->forUser($request->user());
        abort_unless($shipment->vendor_id === $vendor->id, 404);
        abort_unless($shipment->provider === 'sandbox' && config('vsn.shipping.providers.sandbox.simulator_enabled'), 404);
        $data = $request->validate([
            'status' => 'required|in:picked_up,in_transit,out_for_delivery,delivered,delivery_failed,return_to_origin,returned_to_sender',
            'message' => 'nullable|string|max:500',
            'location' => 'nullable|string|max:190',
        ]);
        if ($shipment->dispatch_not_before_at && $shipment->dispatch_not_before_at->isFuture() && in_array($data['status'], ['picked_up','in_transit','out_for_delivery','delivered'], true)) {
            abort(422, 'Scheduled gift shipment cannot be dispatched before its calculated dispatch window.');
        }
        $payload = json_encode([
            'id' => 'sim-'.Str::uuid(),
            'shipment_id' => $shipment->provider_shipment_id,
            'tracking_number' => $shipment->tracking_number,
            'status' => $data['status'],
            'occurred_at' => now()->toIso8601String(),
            'message' => $data['message'] ?? null,
            'location' => $data['location'] ?? null,
        ], JSON_UNESCAPED_SLASHES);
        $signature = 'sha256='.hash_hmac('sha256', $payload, (string) config('vsn.shipping.providers.sandbox.webhook_secret'));
        return new ShipmentResource($process->execute('sandbox', $payload, ['x-vsn-signature' => $signature]));
    }
    /** Handles retry create for the seller shipping controller workflow. */
    public function retryCreate(Request $request, Shipment $shipment, CreateShipment $action): ShipmentResource|JsonResponse
    {
        $vendor=$this->vendors->forUser($request->user());abort_unless($shipment->vendor_id===$vendor->id,404);
        try{return new ShipmentResource($action->retryProviderInitialization($shipment));}catch(ShippingException $e){return $this->error($e);}
    }
    /** Handles sync for the seller shipping controller workflow. */
    public function sync(Request $request, Shipment $shipment, ShipmentLifecycleService $life): ShipmentResource|JsonResponse
    {
        $vendor=$this->vendors->forUser($request->user());abort_unless($shipment->vendor_id===$vendor->id,404);
        try{return new ShipmentResource($life->sync($shipment));}catch(ShippingException $e){return $this->error($e);}
    }
    /** Handles cancel for the seller shipping controller workflow. */
    public function cancel(Request $request, Shipment $shipment, CancelShipment $action): ShipmentResource|JsonResponse
    {
        $vendor=$this->vendors->forUser($request->user());abort_unless($shipment->vendor_id===$vendor->id,404);
        try{return new ShipmentResource($action->execute($shipment));}catch(ShippingException $e){return $this->error($e);}
    }

    /** Handles error for the seller shipping controller workflow. */
    private function error(ShippingException $e): JsonResponse
    {
        $field=$e->field ?? 'shipping';
        return response()->json(['message'=>$e->getMessage(),'errors'=>[$field=>[$e->getMessage()]]],422);
    }

}
