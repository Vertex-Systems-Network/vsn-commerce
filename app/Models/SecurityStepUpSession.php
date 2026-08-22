<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the SecurityStepUpSession class and its project responsibilities. */
class SecurityStepUpSession extends Model{public $timestamps=false;protected $fillable=['public_id','user_id','purpose','device_hash','token_hash','expires_at','last_used_at','revoked_at','created_at'];protected $hidden=['token_hash'];protected function casts():array{return ['expires_at'=>'datetime','last_used_at'=>'datetime','revoked_at'=>'datetime','created_at'=>'datetime'];}public function user():BelongsTo{return $this->belongsTo(User::class);}}
