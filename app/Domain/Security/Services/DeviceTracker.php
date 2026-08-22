<?php
namespace App\Domain\Security\Services;
use App\Enums\SecuritySeverity;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\Request;
/** Defines the DeviceTracker class and its project responsibilities. */
class DeviceTracker {
 /** Initializes the DeviceTracker instance and its dependencies. */
 public function __construct(private readonly SecurityRecorder $events){}
 /** Handles touch for the device tracker workflow. */
 public function touch(User $user,Request $request):UserDevice{
  $raw=(string)$request->header('X-Device-Id'); if($raw===''||strlen($raw)>190)$raw='fallback:'.hash('sha256',$user->id.'|'.(string)$request->userAgent().'|'.$request->ip()); $hash=hash('sha256',$raw);
  $device=UserDevice::query()->where('user_id',$user->id)->where('device_key_hash',$hash)->first(); $isNew=!$device;$wasRevoked=$device?->revoked_at!==null;$trustedIpChanged=$device?->trusted_at!==null&&filled($device?->last_ip)&&$device->last_ip!==$request->ip();
  if(!$device)$device=UserDevice::create(['user_id'=>$user->id,'device_key_hash'=>$hash,'label'=>$this->label((string)$request->userAgent()),'user_agent'=>$request->userAgent(),'last_ip'=>$request->ip(),'last_session_id'=>$request->hasSession()?$request->session()->getId():null,'first_seen_at'=>now(),'last_seen_at'=>now()]);
  else $device->update(['user_agent'=>$request->userAgent(),'last_ip'=>$request->ip(),'last_session_id'=>$request->hasSession()?$request->session()->getId():$device->last_session_id,'last_seen_at'=>now(),'revoked_at'=>null]);
  if($isNew)$this->events->record($user,'new_device_login',SecuritySeverity::Medium,$request,['deviceId'=>$device->id,'label'=>$device->label]);
  if($wasRevoked)$this->events->record($user,'revoked_device_reauthenticated',SecuritySeverity::High,$request,['deviceId'=>$device->id,'label'=>$device->label]);
  if($trustedIpChanged)$this->events->record($user,'trusted_device_ip_changed',SecuritySeverity::Medium,$request,['deviceId'=>$device->id,'previousIp'=>$device->getOriginal('last_ip'),'newIp'=>$request->ip()]); return $device;
 }
 /** Handles label for the device tracker workflow. */
 private function label(string $ua):string{$browser=str_contains($ua,'Chrome')?'Chrome':(str_contains($ua,'Firefox')?'Firefox':(str_contains($ua,'Safari')?'Safari':'Browser'));$os=str_contains($ua,'Windows')?'Windows':(str_contains($ua,'Mac')?'macOS':(str_contains($ua,'Linux')?'Linux':'Device'));return "$browser on $os";}
}
