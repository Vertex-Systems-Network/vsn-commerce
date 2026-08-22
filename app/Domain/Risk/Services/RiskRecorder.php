<?php
namespace App\Domain\Risk\Services;
use App\Models\{RiskEvent,User,Vendor};
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
/** Defines the RiskRecorder class and its project responsibilities. */
class RiskRecorder {
    /** Handles record for the risk recorder workflow. */
    public function record(?User $user,?Vendor $vendor,string $type,string $severity='low',int $scoreDelta=0,?string $scope=null,?string $sourceType=null,?string $sourceId=null,?string $idempotencyKey=null,array $metadata=[]):RiskEvent {
        if($idempotencyKey&&($existing=RiskEvent::query()->where('idempotency_key',$idempotencyKey)->first()))return $existing;
        try{return RiskEvent::create(['public_id'=>(string)Str::ulid(),'user_id'=>$user?->id,'vendor_id'=>$vendor?->id,'event_type'=>$type,'scope'=>$scope,'severity'=>$severity,'score_delta'=>$scoreDelta,'source_type'=>$sourceType,'source_id'=>$sourceId,'idempotency_key'=>$idempotencyKey,'metadata'=>$metadata?:null,'occurred_at'=>now()]);}
        catch(QueryException $e){if($idempotencyKey&&($existing=RiskEvent::query()->where('idempotency_key',$idempotencyKey)->first()))return $existing;throw $e;}
    }
}
