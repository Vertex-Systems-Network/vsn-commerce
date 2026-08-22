<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
/** Defines the GoLiveWindow class and its project responsibilities. */
class GoLiveWindow extends Model
{
    protected $guarded=[];
    /** Handles casts for the go live window workflow. */
    protected function casts():array{return ['thresholds'=>'array','baseline'=>'array','opened_at'=>'datetime','rollback_expires_at'=>'datetime','stabilization_due_at'=>'datetime','stable_at'=>'datetime','rolled_back_at'=>'datetime','closed_at'=>'datetime','observation_interval_minutes'=>'integer','required_healthy_observations'=>'integer'];}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles release candidate manifest for the go live window workflow. */
    public function releaseCandidateManifest():BelongsTo{return $this->belongsTo(ReleaseCandidateManifest::class);}
    /** Handles acceptance run for the go live window workflow. */
    public function acceptanceRun():BelongsTo{return $this->belongsTo(ProductionAcceptanceRun::class,'production_acceptance_run_id');}
    /** Handles deployment run for the go live window workflow. */
    public function deploymentRun():BelongsTo{return $this->belongsTo(DeploymentRun::class);}
    /** Handles incident for the go live window workflow. */
    public function incident():BelongsTo{return $this->belongsTo(IncidentRecord::class,'incident_record_id');}
    /** Handles observations for the go live window workflow. */
    public function observations():HasMany{return $this->hasMany(GoLiveObservation::class);}
    /** Handles signoffs for the go live window workflow. */
    public function signoffs():HasMany{return $this->hasMany(GoLiveStabilizationSignoff::class);}
}
