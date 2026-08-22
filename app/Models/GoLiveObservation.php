<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the GoLiveObservation class and its project responsibilities. */
class GoLiveObservation extends Model
{
    protected $guarded=[];
    /** Handles casts for the go live observation workflow. */
    protected function casts():array{return ['blocker_count'=>'integer','warning_count'=>'integer','snapshot'=>'array','blockers'=>'array','warnings'=>'array','observed_at'=>'datetime'];}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles window for the go live observation workflow. */
    public function window():BelongsTo{return $this->belongsTo(GoLiveWindow::class,'go_live_window_id');}
    /** Handles booted for the go live observation workflow. */
    protected static function booted():void{static::updating(/** Inline callback for this operation. */ fn()=>throw new \LogicException('Go-live observations are immutable.'));static::deleting(/** Inline callback for this operation. */ fn()=>throw new \LogicException('Go-live observations are immutable.'));}
}
