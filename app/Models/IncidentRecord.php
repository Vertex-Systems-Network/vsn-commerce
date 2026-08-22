<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
/** Defines the IncidentRecord class and its project responsibilities. */
class IncidentRecord extends Model
{
    protected $guarded=[];
    /** Handles casts for the incident record workflow. */
    protected function casts():array{return ['evidence'=>'array','started_at'=>'datetime','resolved_at'=>'datetime'];}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles events for the incident record workflow. */
    public function events(){return $this->hasMany(IncidentEvent::class);}
}
