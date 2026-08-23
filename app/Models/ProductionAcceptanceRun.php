<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Defines the ProductionAcceptanceRun class and its project responsibilities. */
class ProductionAcceptanceRun extends Model
{
    protected $guarded = [];

    /** Handles casts for the production acceptance run workflow. */
    protected function casts(): array
    {
        return [
            'checks' => 'array',
            'evaluated_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /** Returns route key name. */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** Handles signoffs for the production acceptance run workflow. */
    public function signoffs(): HasMany
    {
        return $this->hasMany(ProductionAcceptanceSignoff::class, 'acceptance_run_id');
    }

    /** Handles deployment run for the production acceptance run workflow. */
    public function deploymentRun(): BelongsTo
    {
        return $this->belongsTo(DeploymentRun::class, 'deployment_run_id');
    }

    /** Handles release candidate manifest for the production acceptance run workflow. */
    public function releaseCandidateManifest(): HasOne
    {
        return $this->hasOne(ReleaseCandidateManifest::class, 'acceptance_run_id');
    }
}
