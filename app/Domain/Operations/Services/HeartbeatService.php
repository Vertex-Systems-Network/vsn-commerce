<?php
namespace App\Domain\Operations\Services;

use App\Models\OperationalHeartbeat;

/** Defines the HeartbeatService class and its project responsibilities. */
class HeartbeatService
{
    /** Handles beat for the heartbeat service workflow. */
    public function beat(string $name, array $metadata=[]): OperationalHeartbeat
    {
        return OperationalHeartbeat::query()->updateOrCreate(['name'=>$name],[
            'instance_id'=>gethostname() ?: null,
            'last_seen_at'=>now(),
            'metadata'=>$metadata,
        ]);
    }
}
