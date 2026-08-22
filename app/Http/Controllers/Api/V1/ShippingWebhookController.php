<?php
namespace App\Http\Controllers\Api\V1;
use App\Domain\Shipping\Actions\ProcessShippingWebhook;
use App\Domain\Shipping\Exceptions\ShippingException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
/** Defines the ShippingWebhookController class and its project responsibilities. */
class ShippingWebhookController extends Controller
{
    /** Executes the shipping webhook controller operation. */
    public function handle(Request $request,string $provider,ProcessShippingWebhook $action):JsonResponse
    {
        $headers=[];foreach($request->headers->all() as $key=>$values)$headers[strtolower($key)]=$values[0]??null;
        try{$shipment=$action->execute($provider,$request->getContent(),$headers);return response()->json(['data'=>['accepted'=>true,'shipmentId'=>$shipment->public_id,'status'=>$shipment->status->value]]);}
        catch(ShippingException $e){return response()->json(['message'=>$e->getMessage()],422);}
    }
}
