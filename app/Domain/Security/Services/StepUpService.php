<?php
namespace App\Domain\Security\Services;
use App\Models\SecurityStepUpSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
/** Defines the StepUpService class and its project responsibilities. */
class StepUpService{
 /** Handles issue for the step up service workflow. */
 public function issue(User $user,Request $request,string $password,string $purpose):array{
  abort_unless(Hash::check($password,$user->password),422,'Current password is incorrect.');$raw=Str::random(64);$minutes=(int)config('vsn.security.step_up_minutes',10);$device=$this->deviceHash($request);
  SecurityStepUpSession::query()->where('user_id',$user->id)->where('purpose',$purpose)->whereNull('revoked_at')->update(['revoked_at'=>now()]);
  $row=SecurityStepUpSession::create(['public_id'=>(string)Str::uuid(),'user_id'=>$user->id,'purpose'=>$purpose,'device_hash'=>$device,'token_hash'=>hash('sha256',$raw),'expires_at'=>now()->addMinutes($minutes),'created_at'=>now()]);
  return ['token'=>$raw,'expiresAt'=>$row->expires_at->toIso8601String(),'purpose'=>$purpose];
 }
 /** Handles assert for the step up service workflow. */
 public function assert(Request $request,User $user,string $purpose):SecurityStepUpSession{
  $raw=trim((string)$request->header('X-Step-Up-Token',''));abort_if($raw==='',403,'Password confirmation is required for this action.');
  $row=SecurityStepUpSession::query()->where('user_id',$user->id)->where('purpose',$purpose)->where('token_hash',hash('sha256',$raw))->whereNull('revoked_at')->first();
  abort_unless($row&&$row->expires_at->isFuture(),403,'Password confirmation has expired.');
  $device=$this->deviceHash($request);abort_if($row->device_hash&&$row->device_hash!==$device,403,'Password confirmation belongs to another device.');$row->update(['last_used_at'=>now()]);return $row;
 }
 /** Handles device hash for the step up service workflow. */
 private function deviceHash(Request $r):?string{$raw=trim((string)$r->header('X-Device-Id',''));return $raw===''?null:hash('sha256',$raw);}
}
