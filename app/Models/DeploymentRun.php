<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the DeploymentRun class and its project responsibilities. */
class DeploymentRun extends Model
{
    protected $guarded = [];

    /** Handles casts for the deployment run workflow. */
    protected function casts(): array
    {
        return [
            'maintenance_used' => 'boolean',
            'evidence' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** Returns route key name. */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** Handles backup run for the deployment run workflow. */
    public function backupRun(): BelongsTo
    {
        return $this->belongsTo(BackupRun::class);
    }
}
