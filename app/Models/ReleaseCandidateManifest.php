<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Defines the ReleaseCandidateManifest class and its project responsibilities. */
class ReleaseCandidateManifest extends Model
{
    protected $guarded=[];
    /** Handles casts for the release candidate manifest workflow. */
    protected function casts():array{return ['evidence'=>'array','sealed_at'=>'datetime'];}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles acceptance run for the release candidate manifest workflow. */
    public function acceptanceRun(){return $this->belongsTo(ProductionAcceptanceRun::class,'acceptance_run_id');}
    /** Handles deployment run for the release candidate manifest workflow. */
    public function deploymentRun(){return $this->belongsTo(DeploymentRun::class,'deployment_run_id');}
    /** Handles booted for the release candidate manifest workflow. */
    protected static function booted():void
    {
        static::updating(/** Inline callback for this operation. */ fn()=>throw new \LogicException('Release candidate manifests are immutable.'));
        static::deleting(/** Inline callback for this operation. */ fn()=>throw new \LogicException('Release candidate manifests are immutable.'));
    }
}
