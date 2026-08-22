<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
/** Defines the FinanceReconciliationRun class and its project responsibilities. */
class FinanceReconciliationRun extends Model
{
    protected $fillable=['public_id','started_by_user_id','status','started_at','completed_at','issues_count','summary'];
    /** Handles casts for the finance reconciliation run workflow. */
    protected function casts():array{return ['started_at'=>'datetime','completed_at'=>'datetime','issues_count'=>'integer','summary'=>'array'];}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles issues for the finance reconciliation run workflow. */
    public function issues():HasMany{return $this->hasMany(FinanceReconciliationIssue::class);}
}
