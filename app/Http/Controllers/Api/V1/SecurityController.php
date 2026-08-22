<?php
namespace App\Http\Controllers\Api\V1;
use App\Domain\Security\Services\DeviceTracker;
use App\Domain\Security\Services\SecurityRecorder;
use App\Domain\Security\Services\StepUpService;
use App\Enums\SecuritySeverity;
use App\Http\Controllers\Controller;
use App\Models\UserDevice;
use App\Models\MobileApiSession;
use App\Domain\Mobile\Services\MobileTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
/** Defines the SecurityController class and its project responsibilities. */
class SecurityController extends Controller {
 /** Handles step up for the security controller workflow. */
 public function stepUp(Request $r,StepUpService $step,SecurityRecorder $events):JsonResponse{$d=$r->validate(['password'=>'required|string','purpose'=>'required|in:payment_methods']);$issued=$step->issue($r->user(),$r,$d['password'],$d['purpose']);$events->record($r->user(),'security_step_up_confirmed',SecuritySeverity::Medium,$r,['purpose'=>$d['purpose']]);return response()->json(['data'=>$issued]);}
 /** Handles the index request for this resource. */
 public function index(Request $r,DeviceTracker $tracker):JsonResponse{$current=$tracker->touch($r->user(),$r);$devices=$r->user()->devices()->latest('last_seen_at')->get()->map(/** Inline callback for this operation. */ fn($d)=>['id'=>$d->id,'label'=>$d->label,'lastIp'=>$d->last_ip,'lastSeenAt'=>$d->last_seen_at?->toIso8601String(),'trusted'=>$d->trusted_at!==null,'revoked'=>$d->revoked_at!==null,'current'=>$d->id===$current->id]);$mobile=$r->user()->mobileApiSessions()->latest('last_seen_at')->get()->map(/** Inline callback for this operation. */ fn($m)=>['id'=>$m->public_id,'deviceName'=>$m->device_name,'platform'=>$m->platform,'appVersion'=>$m->app_version,'lastIp'=>$m->last_ip,'lastSeenAt'=>$m->last_seen_at?->toIso8601String(),'refreshExpiresAt'=>$m->refresh_expires_at?->toIso8601String(),'revoked'=>$m->revoked_at!==null,'compromisedAt'=>$m->compromised_at?->toIso8601String(),'compromiseReason'=>$m->compromise_reason,'pushEnabled'=>filled($m->push_token_hash),'pushTokenUpdatedAt'=>$m->push_token_updated_at?->toIso8601String()]);$events=$r->user()->securityEvents()->latest('id')->limit(50)->get()->map(/** Inline callback for this operation. */ fn($e)=>['id'=>$e->public_id,'type'=>$e->type,'severity'=>$e->severity->value,'ip'=>$e->ip_address,'createdAt'=>$e->created_at?->toIso8601String(),'metadata'=>$e->metadata]);$alerts=$r->user()->securityEvents()->whereIn('severity',['high','critical'])->where('created_at','>=',now()->subDays(30))->count();return response()->json(['data'=>['devices'=>$devices,'mobileSessions'=>$mobile,'events'=>$events,'summary'=>['highRiskEvents30d'=>$alerts,'trustedDevices'=>$devices->where('trusted',true)->count(),'activeMobileSessions'=>$mobile->where('revoked',false)->count()]]]);}
 /** Handles trust for the security controller workflow. */
 public function trust(Request $r,UserDevice $device,SecurityRecorder $events):JsonResponse{abort_unless($device->user_id===$r->user()->id,403);$d=$r->validate(['password'=>'required|string']);abort_unless(Hash::check($d['password'],$r->user()->password),422,'Current password is incorrect.');$device->update(['trusted_at'=>now(),'revoked_at'=>null]);$events->record($r->user(),'device_trusted',SecuritySeverity::Low,$r,['deviceId'=>$device->id]);return response()->json(['data'=>['ok'=>true]]);}
 /** Handles revoke for the security controller workflow. */
 public function revoke(Request $r,UserDevice $device,SecurityRecorder $events):JsonResponse{abort_unless($device->user_id===$r->user()->id,403);$device->update(['revoked_at'=>now(),'trusted_at'=>null]);if($device->last_session_id)DB::table('sessions')->where('id',$device->last_session_id)->where('user_id',$r->user()->id)->delete();$events->record($r->user(),'device_revoked',SecuritySeverity::Medium,$r,['deviceId'=>$device->id]);return response()->json(['data'=>['ok'=>true]]);}

 /** Handles revoke others for the security controller workflow. */
 public function revokeOthers(Request $r,MobileTokenService $tokens,SecurityRecorder $events):JsonResponse{
  $d=$r->validate(['password'=>'required|string']);abort_unless(Hash::check($d['password'],$r->user()->password),422,'Current password is incorrect.');$user=$r->user();$currentSession=$r->hasSession()?$r->session()->getId():null;
  $web=DB::table('sessions')->where('user_id',$user->id);if($currentSession)$web->where('id','!=',$currentSession);$webCount=$web->delete();
  $devices=$user->devices()->where('id','!=',optional($user->devices()->where('last_session_id',$currentSession)->first())->id)->whereNull('revoked_at')->update(['revoked_at'=>now(),'trusted_at'=>null]);
  $currentToken=$user->currentAccessToken();$except=$currentToken instanceof PersonalAccessToken?(int)$currentToken->getKey():null;$mobile=$tokens->revokeAll($user,$except);$events->record($user,'other_sessions_revoked',SecuritySeverity::High,$r,['webSessions'=>$webCount,'devices'=>$devices,'mobileSessions'=>$mobile]);
  return response()->json(['data'=>['ok'=>true,'webSessions'=>$webCount,'devices'=>$devices,'mobileSessions'=>$mobile]]);
 }

 /** Handles change password for the security controller workflow. */
 public function changePassword(Request $r,SecurityRecorder $events):JsonResponse{
  $d=$r->validate(['current_password'=>'required|string','password'=>'required|string|min:10|confirmed']);
  abort_unless(Hash::check($d['current_password'],$r->user()->password),422,'Current password is incorrect.');
  $user=$r->user();
  DB::transaction(/** Inline callback for this operation. */ function()use($user,$d,$r){
   $user->forceFill(['password'=>$d['password'],'remember_token'=>\Illuminate\Support\Str::random(60)])->save();
   $currentSession=$r->hasSession()?$r->session()->getId():null;
   $sessions=DB::table('sessions')->where('user_id',$user->id);if($currentSession)$sessions->where('id','!=',$currentSession);$sessions->delete();
   if(method_exists($user,'tokens'))$user->tokens()->delete();
   if(method_exists($user,'mobileApiSessions'))$user->mobileApiSessions()->whereNull('revoked_at')->update(['access_token_id'=>null,'refresh_token_hash'=>null,'revoked_at'=>now()]);
  },3);
  $events->record($user,'password_changed',SecuritySeverity::High,$r);
  return response()->json(['data'=>['ok'=>true]]);
 }
}
