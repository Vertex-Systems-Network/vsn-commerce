<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the AdminAuditLog class and its project responsibilities. */
class AdminAuditLog extends Model { public $timestamps=false; protected $fillable=['public_id','actor_user_id','action','method','path','response_status','target_type','target_id','ip_address','user_agent','request_hash','metadata','created_at']; protected function casts():array{return ['metadata'=>'array','created_at'=>'datetime'];} public function actor():BelongsTo{return $this->belongsTo(User::class,'actor_user_id');} protected static function booted():void{static::updating(/** Inline callback for this operation. */ fn()=>throw new \LogicException('Admin audit logs are immutable.'));static::deleting(/** Inline callback for this operation. */ fn()=>throw new \LogicException('Admin audit logs are immutable.'));} }
