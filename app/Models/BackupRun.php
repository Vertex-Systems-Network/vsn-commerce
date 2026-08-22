<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Defines the BackupRun class and its project responsibilities. */
class BackupRun extends Model
{
    protected $guarded = [];
    /** Handles casts for the backup run workflow. */
    protected function casts(): array { return ['started_at'=>'datetime','completed_at'=>'datetime','verified_at'=>'datetime','expires_at'=>'datetime','metadata'=>'array']; }
    /** Returns route key name. */
    public function getRouteKeyName(): string { return 'public_id'; }
}
