<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Http\Resources\ShipmentResource;
use App\Models\Shipment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
/** Defines the ShipmentController class and its project responsibilities. */
class ShipmentController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(Request $request):AnonymousResourceCollection
    {
        $rows=Shipment::query()->whereHas('order',/** Inline callback for this operation. */ fn($q)=>$q->where('user_id',$request->user()->id))->with(['order','vendor','vendorOrder.vendor','items.orderItem','events'])->latest()->paginate(50);
        return ShipmentResource::collection($rows);
    }
    /** Handles the show request for this resource. */
    public function show(Request $request,Shipment $shipment):ShipmentResource
    {
        abort_unless($shipment->order()->where('user_id',$request->user()->id)->exists(),404);
        return new ShipmentResource($shipment->load(['order','vendor','vendorOrder.vendor','items.orderItem','events']));
    }
}
