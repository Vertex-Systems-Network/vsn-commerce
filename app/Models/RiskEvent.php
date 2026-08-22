<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the RiskEvent class and its project responsibilities. */
class RiskEvent extends Model {
    protected $fillable=['public_id','user_id','vendor_id','event_type','scope','severity','score_delta','source_type','source_id','idempotency_key','metadata','occurred_at'];
    /** Handles casts for the risk event workflow. */
    protected function casts():array{return ['score_delta'=>'integer','metadata'=>'array','occurred_at'=>'datetime'];}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles user for the risk event workflow. */
    public function user():BelongsTo{return $this->belongsTo(User::class);}
    /** Handles vendor for the risk event workflow. */
    public function vendor():BelongsTo{return $this->belongsTo(Vendor::class);}
    /** Handles booted for the risk event workflow. */
    protected static function booted():void{static::updating(/** Inline callback for this operation. */ fn()=>throw new \LogicException('Risk evidence is immutable.'));static::deleting(/** Inline callback for this operation. */ fn()=>throw new \LogicException('Risk evidence is immutable.'));}
}
