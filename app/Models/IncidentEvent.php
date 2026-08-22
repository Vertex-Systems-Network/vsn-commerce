<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Defines the IncidentEvent class and its project responsibilities. */
class IncidentEvent extends Model
{
    protected $guarded = [];
    /** Handles casts for the incident event workflow. */
    protected function casts(): array { return ['evidence'=>'array','occurred_at'=>'datetime']; }
    /** Returns route key name. */
    public function getRouteKeyName(): string { return 'public_id'; }
    /** Handles incident for the incident event workflow. */
    public function incident() { return $this->belongsTo(IncidentRecord::class, 'incident_record_id'); }
}
