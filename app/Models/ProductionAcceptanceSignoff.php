<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
/** Defines the ProductionAcceptanceSignoff class and its project responsibilities. */
class ProductionAcceptanceSignoff extends Model
{
    protected $guarded=[];
    /** Handles casts for the production acceptance signoff workflow. */
    protected function casts():array{return ['evidence'=>'array','signed_at'=>'datetime'];}
    /** Executes the production acceptance signoff operation. */
    public function run(){return $this->belongsTo(ProductionAcceptanceRun::class,'acceptance_run_id');}
    /** Handles booted for the production acceptance signoff workflow. */
    protected static function booted():void
    {
        static::updating(/** Inline callback for this operation. */ fn()=>throw new \LogicException('Production acceptance sign-offs are immutable.'));
        static::deleting(/** Inline callback for this operation. */ fn()=>throw new \LogicException('Production acceptance sign-offs are immutable.'));
    }
}
