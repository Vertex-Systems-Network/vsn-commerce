<?php
namespace App\Domain\Security\Services;
use App\Domain\Notifications\Actions\PublishMarketplaceNotification;
use App\Enums\SecuritySeverity;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
/** Defines the SecurityRecorder class and its project responsibilities. */
class SecurityRecorder
{
 /** Initializes the SecurityRecorder instance and its dependencies. */
 public function __construct(private readonly PublishMarketplaceNotification $publish){}
 /** Handles record for the security recorder workflow. */
 public function record(?User $user,string $type,SecuritySeverity $severity,Request $request,array $metadata=[]):SecurityEvent
 {
  $event=SecurityEvent::create(['public_id'=>(string)Str::uuid(),'user_id'=>$user?->id,'type'=>$type,'severity'=>$severity,'ip_address'=>$request->ip(),'user_agent'=>substr((string)$request->userAgent(),0,2000),'session_id'=>$request->hasSession()?$request->session()->getId():null,'metadata'=>$metadata?:null,'created_at'=>now()]);
  if($user&&in_array($type,['new_device_login','revoked_device_reauthenticated','trusted_device_ip_changed','password_changed','other_sessions_revoked','mobile_refresh_replay_detected'],true)){
   [$title,$body]=match($type){
    'new_device_login'=>['New device signed in','A new device signed in to your VSN Ecommerce account. Review Security if you do not recognize it.'],
    'revoked_device_reauthenticated'=>['Revoked device signed in again','A previously revoked device authenticated again using valid credentials. Review your account immediately if this was not you.'],
    'trusted_device_ip_changed'=>['Trusted device network changed','A trusted device signed in from a different network address. Review recent security activity if unexpected.'],
    'password_changed'=>['Password changed','Your VSN Ecommerce password was changed and other sessions were invalidated.'],
    'mobile_refresh_replay_detected'=>['Android session revoked','A rotated Android refresh token was reused. The affected mobile session was revoked as a security precaution.'],
    default=>['Other sessions revoked','Other web and mobile sessions were revoked from your VSN Ecommerce account.'],
   };
   $this->publish->execute($user,'security','security.'.$type,$title,$body,'security-event:'.$event->public_id,'/account/security','security_event',$event->public_id,['severity'=>$severity->value,'ip'=>$request->ip()],true);
  }
  return $event;
 }
}
