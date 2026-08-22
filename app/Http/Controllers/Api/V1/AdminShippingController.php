<?php
namespace App\Http\Controllers\Api\V1;
use App\Domain\Shipping\Services\ShippingSlaService;
use App\Domain\Shipping\Services\ShipmentLifecycleService;
use App\Domain\Shipping\Actions\CancelShipment;
use App\Domain\Shipping\Exceptions\ShippingException;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\ShipmentResource;
use App\Models\Shipment;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
/** Defines the AdminShippingController class and its project responsibilities. */
class AdminShippingController extends Controller
{
    /** Handles quality for the admin shipping controller workflow. */
    public function quality(Request $request,ShippingSlaService $sla):JsonResponse{$this->admin($request);$rows=Vendor::query()->orderBy('name')->get()->map(/** Inline callback for this operation. */ fn($v)=>$sla->vendorMetrics($v));return response()->json(['data'=>$rows]);}
    /** Handles shipments for the admin shipping controller workflow. */
    public function shipments(Request $request):JsonResponse{$this->admin($request);$rows=Shipment::query()->with(['order','vendor','vendorOrder.vendor','items.orderItem','events'])->latest()->limit(200)->get();return response()->json(['data'=>ShipmentResource::collection($rows)->resolve($request)]);}
    /** Handles retry create for the admin shipping controller workflow. */
    public function retryCreate(Request $request,Shipment $shipment,\App\Domain\Shipping\Actions\CreateShipment $action):JsonResponse{$this->manage($request);try{return response()->json(['data'=>(new ShipmentResource($action->retryProviderInitialization($shipment)))->resolve($request)]);}catch(ShippingException $e){return response()->json(['message'=>$e->getMessage()],422);}}
    /** Handles sync for the admin shipping controller workflow. */
    public function sync(Request $request,Shipment $shipment,ShipmentLifecycleService $life):JsonResponse{$this->admin($request);try{return response()->json(['data'=>(new ShipmentResource($life->sync($shipment)))->resolve($request)]);}catch(ShippingException $e){return response()->json(['message'=>$e->getMessage()],422);}}
    /** Handles cancel for the admin shipping controller workflow. */
    public function cancel(Request $request,Shipment $shipment,CancelShipment $action):JsonResponse{$this->manage($request);try{return response()->json(['data'=>(new ShipmentResource($action->execute($shipment)))->resolve($request)]);}catch(ShippingException $e){return response()->json(['message'=>$e->getMessage()],422);}}

    /** Handles manage for the admin shipping controller workflow. */
    private function manage(Request $request):void{$role=$request->user()?->role;$value=$role instanceof UserRole?$role->value:(string)$role;abort_unless(in_array($value,[UserRole::Admin->value,UserRole::SuperAdmin->value],true),403);}
    /** Handles admin for the admin shipping controller workflow. */
    private function admin(Request $request):void{$role=$request->user()?->role;$value=$role instanceof UserRole?$role->value:(string)$role;abort_unless(in_array($value,[UserRole::Support->value,UserRole::Admin->value,UserRole::SuperAdmin->value],true),403);}
}
