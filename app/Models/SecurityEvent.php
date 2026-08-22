<?php
namespace App\Models;
use App\Enums\SecuritySeverity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the SecurityEvent class and its project responsibilities. */
class SecurityEvent extends Model { public $timestamps=false; protected $fillable=['public_id','user_id','type','severity','ip_address','user_agent','session_id','metadata','created_at']; protected function casts():array{return ['severity'=>SecuritySeverity::class,'metadata'=>'array','created_at'=>'datetime'];} public function user():BelongsTo{return $this->belongsTo(User::class);} protected static function booted():void{static::updating(/** Inline callback for this operation. */ fn()=>throw new \LogicException('Security events are immutable.'));static::deleting(/** Inline callback for this operation. */ fn()=>throw new \LogicException('Security events are immutable.'));} }
