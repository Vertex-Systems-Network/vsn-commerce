<?php
namespace App\Domain\Finance\Actions;
use App\Enums\VendorPayoutStatus;
use App\Enums\VendorSettlementStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayout;
use App\Models\VendorPayoutMethod;
use App\Models\VendorSettlement;
use App\Enums\KycVerificationStatus;
use App\Enums\KycVerificationType;
use App\Domain\Risk\Services\RiskGate;
use App\Domain\Risk\Services\RiskEvaluator;
use App\Domain\Risk\Exceptions\RiskBlockedException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
/** Defines the RequestVendorPayout class and its project responsibilities. */
class RequestVendorPayout
{
    /** Initializes the RequestVendorPayout instance and its dependencies. */
    public function __construct(private readonly ReconcileVendorSettlements $reconcile, private readonly RiskGate $risk, private readonly RiskEvaluator $riskEvaluator){}
    /** Executes the request vendor payout operation. */
    public function execute(User $user,Vendor $vendor,string $idempotencyKey,?int $requestedMinor=null):VendorPayout
    {
        if (config('vsn.security.seller_payout_requires_phone', true) && !$user->profile?->phone_verified_at) abort(422, 'Phone verification is required before requesting a payout.');
        if (config('vsn.security.seller_payout_requires_identity', true) && !$user->kycVerifications()->where('type',KycVerificationType::GovernmentId->value)->where('status',KycVerificationStatus::Approved->value)->where(/** Inline callback for this operation. */ fn($q)=>$q->whereNull('expires_at')->orWhere('expires_at','>',now()))->exists()) abort(422, 'Approved identity verification is required before requesting a payout.');
        $existing=VendorPayout::query()->where('idempotency_key',$idempotencyKey)->first();if($existing){abort_unless($existing->vendor_id===$vendor->id,409);return $existing->load('items.settlement');}
        $this->riskEvaluator->user($user,'payout_request'); $this->riskEvaluator->vendor($vendor,'payout_request');
        try { $this->risk->payout($user,$vendor); } catch (RiskBlockedException $e) { abort(422,$e->getMessage()); }
        $this->reconcile->execute($vendor->id);
        try {
            return DB::transaction(/** Inline callback for this operation. */ function()use($user,$vendor,$idempotencyKey,$requestedMinor):VendorPayout{
                $existing=VendorPayout::query()->where('idempotency_key',$idempotencyKey)->lockForUpdate()->first();if($existing)return $existing->load('items.settlement');
                $method=VendorPayoutMethod::query()->where('vendor_id',$vendor->id)->where('is_default',true)->whereNull('revoked_at')->lockForUpdate()->first();
                if(config('vsn.finance.require_verified_payout_method',true)){
                    if(!$method)abort(422,'Add a default payout method before requesting a payout.');
                    if(!$method->verified_at)abort(422,'Your default payout method must be verified before requesting a payout.');
                }
                $rows=VendorSettlement::query()->where('vendor_id',$vendor->id)->where('status',VendorSettlementStatus::Available->value)->orderBy('eligible_at')->orderBy('id')->lockForUpdate()->get();
                $available=(int)$rows->sum(/** Inline callback for this operation. */ fn($s)=>$s->availableMinor());$minimum=(int)config('vsn.finance.minimum_payout_minor',100000);$amount=$requestedMinor??$available;
                if($amount<$minimum)abort(422,"Minimum payout is {$minimum} minor units.");if($amount>$available)abort(422,'Requested payout exceeds available seller balance.');
                $currencies=$rows->filter(/** Inline callback for this operation. */ fn($s)=>$s->availableMinor()>0)->pluck('currency')->unique();if($currencies->count()>1)abort(422,'Available seller settlements contain mixed currencies.');$currency=$currencies->first()?:config('vsn.currency','PKR');
                $payout=VendorPayout::create(['public_id'=>(string)Str::ulid(),'vendor_id'=>$vendor->id,'vendor_payout_method_id'=>$method?->id,'requested_by_user_id'=>$user->id,'status'=>VendorPayoutStatus::Requested,'currency'=>$currency,'amount_minor'=>$amount,'payout_method_snapshot'=>$method?['type'=>$method->type,'label'=>$method->label,'accountHolderName'=>$method->account_holder_name,'bankName'=>$method->bank_name,'accountLast4'=>$method->account_last4,'routingLast4'=>$method->routing_last4,'countryCode'=>$method->country_code,'currency'=>$method->currency]:null,'idempotency_key'=>$idempotencyKey]);
                $remaining=$amount;foreach($rows as $s){if($remaining<=0)break;$take=min($remaining,$s->availableMinor());if($take<=0)continue;$payout->items()->create(['vendor_settlement_id'=>$s->id,'amount_minor'=>$take]);$s->increment('payout_reserved_minor',$take);$s->vendorOrder()->increment('payout_reserved_minor',$take);$s->update(['status'=>VendorSettlementStatus::PayoutPending]);$remaining-=$take;}
                if($remaining!==0)throw new \LogicException('Payout allocation failed to match requested amount.');return $payout->load('items.settlement');
            },3);
        } catch (QueryException $e) {
            if(($e->errorInfo[0]??$e->getCode())==='23505' || str_contains(strtolower($e->getMessage()), 'unique')){
                $existing=VendorPayout::query()->where('idempotency_key',$idempotencyKey)->first();
                if($existing){abort_unless($existing->vendor_id===$vendor->id,409);return $existing->load('items.settlement');}
            }
            throw $e;
        }
    }
}
