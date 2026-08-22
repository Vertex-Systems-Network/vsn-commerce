<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
/** Defines the ProviderRuntimeStatus class and its project responsibilities. */
class ProviderRuntimeStatus extends Model
{
    protected $fillable=['provider_type','provider_code','status','production_ready','latency_ms','message','details','checked_at'];
    /** Handles casts for the provider runtime status workflow. */
    protected function casts():array{return ['production_ready'=>'boolean','latency_ms'=>'integer','details'=>'array','checked_at'=>'datetime'];}
}
