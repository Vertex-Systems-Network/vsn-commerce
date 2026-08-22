<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
/** Defines the DisasterRecoveryDrill class and its project responsibilities. */
class DisasterRecoveryDrill extends Model
{
    protected $guarded=[];
    /** Handles casts for the disaster recovery drill workflow. */
    protected function casts():array{return ['evidence'=>'array','started_at'=>'datetime','completed_at'=>'datetime'];}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles backup run for the disaster recovery drill workflow. */
    public function backupRun(){return $this->belongsTo(BackupRun::class);}
    /** Handles booted for the disaster recovery drill workflow. */
    protected static function booted():void
    {
        static::updating(/** Inline callback for this operation. */ fn()=>throw new \LogicException('Disaster recovery evidence is immutable.'));
        static::deleting(/** Inline callback for this operation. */ fn()=>throw new \LogicException('Disaster recovery evidence is immutable.'));
    }
}
