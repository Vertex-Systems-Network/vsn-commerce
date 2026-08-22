<?php
namespace App\Http\Controllers\Api\V1;
use App\Enums\SecuritySeverity;
use App\Domain\Security\Services\SecurityRecorder;
use App\Domain\Security\Services\SmsProviderManager;
use App\Http\Controllers\Controller;
use App\Models\OneTimeCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
/** Defines the PhoneVerificationController class and its project responsibilities. */
class PhoneVerificationController extends Controller {
 /** Handles send for the phone verification controller workflow. */
 public function send(Request $r,SecurityRecorder $events,SmsProviderManager $providers):JsonResponse{$d=$r->validate(['phone'=>'required|string|min:7|max:40']);$code=(string)random_int(100000,999999);$providers->provider()->send($d['phone'], 'Your VSN verification code is '.$code.'. It expires in 10 minutes.');OneTimeCode::query()->where('purpose','phone_verify')->where('identifier',$d['phone'])->whereNull('consumed_at')->update(['consumed_at'=>now()]);OneTimeCode::create(['purpose'=>'phone_verify','identifier'=>$d['phone'],'code_hash'=>Hash::make($code),'expires_at'=>now()->addMinutes(10)]);$r->user()->profile()->updateOrCreate([],['phone'=>$d['phone'],'phone_verified_at'=>null]);$events->record($r->user(),'phone_verification_code_sent',SecuritySeverity::Low,$r);return response()->json(['data'=>['sent'=>true,'expiresIn'=>600,'sandboxCode'=>app()->environment(['local','testing'])?$code:null]]);}
 /** Handles verify for the phone verification controller workflow. */
 public function verify(Request $r,SecurityRecorder $events):JsonResponse{$d=$r->validate(['phone'=>'required|string|max:40','code'=>'required|digits:6']);$otp=OneTimeCode::query()->where('purpose','phone_verify')->where('identifier',$d['phone'])->whereNull('consumed_at')->where('expires_at','>',now())->latest('id')->first();if(!$otp||$otp->attempts>=5||!Hash::check($d['code'],$otp->code_hash)){if($otp)$otp->increment('attempts');$events->record($r->user(),'phone_verification_failed',SecuritySeverity::Medium,$r);return response()->json(['message'=>'Invalid or expired verification code.'],422);}$otp->update(['consumed_at'=>now()]);$r->user()->profile()->updateOrCreate([],['phone'=>$d['phone'],'phone_verified_at'=>now()]);$events->record($r->user(),'phone_verified',SecuritySeverity::Low,$r);return response()->json(['data'=>['verified'=>true,'phone'=>$d['phone']]]);}
}
