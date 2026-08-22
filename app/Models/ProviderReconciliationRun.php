<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
/** Defines the ProviderReconciliationRun class and its project responsibilities. */
class ProviderReconciliationRun extends Model
{
    protected $fillable=['public_id','provider_type','provider_code','status','checked_count','matched_count','mismatch_count','error_count','details','started_at','completed_at'];
    /** Handles casts for the provider reconciliation run workflow. */
    protected function casts():array{return ['checked_count'=>'integer','matched_count'=>'integer','mismatch_count'=>'integer','error_count'=>'integer','details'=>'array','started_at'=>'datetime','completed_at'=>'datetime'];}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
}
