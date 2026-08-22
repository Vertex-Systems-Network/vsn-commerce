<?php
namespace App\Http\Controllers\Api\V1;
use App\Domain\Kyc\Actions\ProcessKycWebhook;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
/** Defines the KycWebhookController class and its project responsibilities. */
final class KycWebhookController extends Controller
{
    /** Executes the kyc webhook controller operation. */
    public function handle(Request $request,string $provider,ProcessKycWebhook $process):JsonResponse
    {
        try{$event=$process->execute($provider,$request->getContent(),$request->headers->all());return response()->json(['data'=>['accepted'=>true,'eventId'=>$event->provider_event_id,'status'=>$event->status]]);}
        catch(\Throwable $e){report($e);return response()->json(['message'=>'KYC webhook processing failed.'],422);}
    }
}
