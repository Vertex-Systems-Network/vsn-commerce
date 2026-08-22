<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Defines the LaunchGateRun class and its project responsibilities. */
class LaunchGateRun extends Model
{
    protected $guarded = [];
    /** Handles casts for the launch gate run workflow. */
    protected function casts(): array { return ['checks'=>'array','ran_at'=>'datetime']; }
    /** Returns route key name. */
    public function getRouteKeyName(): string { return 'public_id'; }
}
