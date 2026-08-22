<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the UserDevice class and its project responsibilities. */
class UserDevice extends Model { protected $fillable=['user_id','device_key_hash','label','user_agent','last_ip','last_session_id','first_seen_at','last_seen_at','trusted_at','revoked_at']; protected function casts():array{return ['first_seen_at'=>'datetime','last_seen_at'=>'datetime','trusted_at'=>'datetime','revoked_at'=>'datetime'];} public function user():BelongsTo{return $this->belongsTo(User::class);} }
