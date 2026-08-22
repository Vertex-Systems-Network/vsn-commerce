<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the GoLiveStabilizationSignoff class and its project responsibilities. */
class GoLiveStabilizationSignoff extends Model
{
    public $timestamps=false;
    protected $guarded=[];
    /** Handles casts for the go live stabilization signoff workflow. */
    protected function casts():array{return ['evidence'=>'array','signed_at'=>'datetime'];}
    /** Handles window for the go live stabilization signoff workflow. */
    public function window():BelongsTo{return $this->belongsTo(GoLiveWindow::class,'go_live_window_id');}
    /** Handles signer for the go live stabilization signoff workflow. */
    public function signer():BelongsTo{return $this->belongsTo(User::class,'signed_by_user_id');}
    /** Handles booted for the go live stabilization signoff workflow. */
    protected static function booted():void{static::updating(/** Inline callback for this operation. */ fn()=>throw new \LogicException('Go-live stabilization sign-offs are immutable.'));static::deleting(/** Inline callback for this operation. */ fn()=>throw new \LogicException('Go-live stabilization sign-offs are immutable.'));}
}
