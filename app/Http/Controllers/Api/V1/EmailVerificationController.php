<?php
namespace App\Http\Controllers\Api\V1;
use App\Domain\Notifications\Actions\PublishMarketplaceNotification;
use App\Domain\Security\Services\SecurityRecorder;
use App\Enums\SecuritySeverity;
use App\Http\Controllers\Controller;
use App\Models\OneTimeCode;
use App\Notifications\EmailVerificationCodeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
/** Defines the EmailVerificationController class and its project responsibilities. */
class EmailVerificationController extends Controller
{
    /** Handles send for the email verification controller workflow. */
    public function send(Request $request,SecurityRecorder $events):JsonResponse
    {
        $user=$request->user();
        if($user->hasVerifiedEmail())return response()->json(['data'=>['verified'=>true,'email'=>$user->email]]);
        $key='email-verify:'.$user->id.'|'.$request->ip();
        if(RateLimiter::tooManyAttempts($key,3))return response()->json(['message'=>'Too many verification-code requests. Try again later.'],429);
        RateLimiter::hit($key,600);
        OneTimeCode::query()->where('purpose','email_verify')->where('identifier',$user->email)->whereNull('consumed_at')->update(['consumed_at'=>now()]);
        $code=(string)random_int(100000,999999);
        OneTimeCode::create(['purpose'=>'email_verify','identifier'=>$user->email,'code_hash'=>Hash::make($code),'attempts'=>0,'expires_at'=>now()->addMinutes(10)]);
        $user->notify(new EmailVerificationCodeNotification($code));
        $events->record($user,'email_verification_code_sent',SecuritySeverity::Low,$request);
        return response()->json(['data'=>['sent'=>true,'expiresIn'=>600,'sandboxCode'=>app()->environment(['local','testing'])?$code:null]]);
    }
    /** Handles verify for the email verification controller workflow. */
    public function verify(Request $request,SecurityRecorder $events,PublishMarketplaceNotification $publish):JsonResponse
    {
        $data=$request->validate(['code'=>['required','digits:6']]);$user=$request->user();
        if($user->hasVerifiedEmail())return response()->json(['data'=>['verified'=>true,'email'=>$user->email]]);
        $otp=OneTimeCode::query()->where('purpose','email_verify')->where('identifier',$user->email)->whereNull('consumed_at')->where('expires_at','>',now())->latest('id')->first();
        if(!$otp||$otp->attempts>=5||!Hash::check($data['code'],$otp->code_hash)){if($otp)$otp->increment('attempts');$events->record($user,'email_verification_failed',SecuritySeverity::Medium,$request);return response()->json(['message'=>'Invalid or expired verification code.'],422);}
        $otp->update(['consumed_at'=>now()]);$user->forceFill(['email_verified_at'=>now()])->save();$events->record($user,'email_verified',SecuritySeverity::Low,$request);
        $publish->execute($user,'security','security.email_verified','Email verified','Your email address was verified successfully.','security:email-verified:'.$user->id,null,'user',(string)$user->id,[],true);
        return response()->json(['data'=>['verified'=>true,'email'=>$user->email]]);
    }
}
