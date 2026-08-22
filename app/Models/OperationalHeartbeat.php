<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Defines the OperationalHeartbeat class and its project responsibilities. */
class OperationalHeartbeat extends Model
{
    protected $guarded = [];
    /** Handles casts for the operational heartbeat workflow. */
    protected function casts(): array { return ['last_seen_at'=>'datetime','metadata'=>'array']; }
}
