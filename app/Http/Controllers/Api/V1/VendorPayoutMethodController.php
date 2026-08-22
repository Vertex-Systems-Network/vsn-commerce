<?php
namespace App\Http\Controllers\Api\V1;
use App\Domain\Finance\Services\VendorResolver;
use App\Http\Controllers\Controller;
use App\Models\VendorPayoutMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Defines the VendorPayoutMethodController class and its project responsibilities. */
class VendorPayoutMethodController extends Controller
{
    /** Initializes the VendorPayoutMethodController instance and its dependencies. */
    public function __construct(private readonly VendorResolver $vendors){}

    /** Handles the index request for this resource. */
    public function index(Request $request):JsonResponse
    {
        $vendor=$this->vendors->forUser($request->user());
        $rows=$vendor->payoutMethods()->latest()->get();
        return response()->json(['data'=>$rows->map(/** Inline callback for this operation. */ fn($m)=>$this->resource($m))->all()]);
    }

    /** Handles the store request for this resource. */
    public function store(Request $request):JsonResponse
    {
        $vendor=$this->vendors->forUser($request->user());
        $d=$request->validate([
            'password'=>'required|string',
            'type'=>'nullable|in:bank_transfer',
            'label'=>'nullable|string|max:100',
            'accountHolderName'=>'required|string|max:160',
            'bankName'=>'required|string|max:160',
            'accountIdentifier'=>'required|string|min:4|max:190',
            'routingIdentifier'=>'nullable|string|max:190',
            'countryCode'=>'nullable|string|size:2',
            'currency'=>'nullable|string|size:3',
            'makeDefault'=>'nullable|boolean',
        ]);
        abort_unless(Hash::check($d['password'],$request->user()->password),422,'Current password is incorrect.');
        $method=DB::transaction(/** Inline callback for this operation. */ function()use($vendor,$d):VendorPayoutMethod{
            $hasActive=$vendor->payoutMethods()->whereNull('revoked_at')->exists();
            $makeDefault=(bool)($d['makeDefault']??!$hasActive);
            if($makeDefault)$vendor->payoutMethods()->whereNull('revoked_at')->update(['is_default'=>false]);
            $account=trim($d['accountIdentifier']);$routing=trim((string)($d['routingIdentifier']??''));
            return $vendor->payoutMethods()->create([
                'public_id'=>(string)Str::ulid(),'type'=>$d['type']??'bank_transfer','label'=>$d['label']??null,
                'account_holder_name'=>$d['accountHolderName'],'bank_name'=>$d['bankName'],
                'account_identifier_cipher'=>$account,'account_last4'=>substr($account,-4),
                'routing_identifier_cipher'=>$routing!==''?$routing:null,'routing_last4'=>$routing!==''?substr($routing,-4):null,
                'country_code'=>isset($d['countryCode'])?strtoupper($d['countryCode']):null,
                'currency'=>strtoupper($d['currency']??config('vsn.currency','PKR')),'is_default'=>$makeDefault,
                'metadata'=>['created_source'=>'seller_center'],
            ]);
        },3);
        return response()->json(['data'=>$this->resource($method)],201);
    }

    /** Handles make default for the vendor payout method controller workflow. */
    public function makeDefault(Request $request,VendorPayoutMethod $payoutMethod):JsonResponse
    {
        $vendor=$this->vendors->forUser($request->user());abort_unless($payoutMethod->vendor_id===$vendor->id,404);abort_if($payoutMethod->revoked_at,422,'Revoked payout methods cannot be default.');
        $d=$request->validate(['password'=>'required|string']);abort_unless(Hash::check($d['password'],$request->user()->password),422,'Current password is incorrect.');
        DB::transaction(/** Inline callback for this operation. */ function()use($vendor,$payoutMethod):void{$vendor->payoutMethods()->whereNull('revoked_at')->update(['is_default'=>false]);$payoutMethod->update(['is_default'=>true]);},3);
        return response()->json(['data'=>$this->resource($payoutMethod->fresh())]);
    }

    /** Handles the destroy request for this resource. */
    public function destroy(Request $request,VendorPayoutMethod $payoutMethod):JsonResponse
    {
        $vendor=$this->vendors->forUser($request->user());abort_unless($payoutMethod->vendor_id===$vendor->id,404);
        $d=$request->validate(['password'=>'required|string']);abort_unless(Hash::check($d['password'],$request->user()->password),422,'Current password is incorrect.');
        abort_if($vendor->payouts()->where('vendor_payout_method_id',$payoutMethod->id)->whereIn('status',['requested','approved','processing','failed'])->exists(),422,'This payout method is attached to an active payout. Cancel or complete that payout first.');
        DB::transaction(/** Inline callback for this operation. */ function()use($vendor,$payoutMethod):void{
            $wasDefault=$payoutMethod->is_default;$payoutMethod->update(['revoked_at'=>now(),'is_default'=>false]);
            if($wasDefault){$next=$vendor->payoutMethods()->whereNull('revoked_at')->whereKeyNot($payoutMethod->id)->latest('id')->first();if($next)$next->update(['is_default'=>true]);}
        },3);
        return response()->json(['data'=>['revoked'=>true]]);
    }

    /** Handles resource for the vendor payout method controller workflow. */
    private function resource(VendorPayoutMethod $m):array
    {
        return ['id'=>$m->public_id,'type'=>$m->type,'label'=>$m->label,'accountHolderName'=>$m->account_holder_name,'bankName'=>$m->bank_name,'accountLast4'=>$m->account_last4,'routingLast4'=>$m->routing_last4,'countryCode'=>$m->country_code,'currency'=>$m->currency,'isDefault'=>(bool)$m->is_default,'verified'=>(bool)$m->verified_at,'verifiedAt'=>$m->verified_at?->toIso8601String(),'revoked'=>(bool)$m->revoked_at,'createdAt'=>$m->created_at?->toIso8601String()];
    }
}
