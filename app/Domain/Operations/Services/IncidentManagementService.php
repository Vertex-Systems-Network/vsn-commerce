<?php
namespace App\Domain\Operations\Services;

use App\Models\IncidentEvent;
use App\Models\IncidentRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Defines the IncidentManagementService class and its project responsibilities. */
class IncidentManagementService
{
    /** Handles open for the incident management service workflow. */
    public function open(?int $actorUserId, string $severity, string $type, string $title, ?string $summary = null, array $evidence = []): IncidentRecord
    {
        return DB::transaction(/** Inline callback for this operation. */ function () use ($actorUserId,$severity,$type,$title,$summary,$evidence) {
            $incident = IncidentRecord::query()->create([
                'public_id'=>(string) Str::ulid(), 'actor_user_id'=>$actorUserId,
                'severity'=>$severity, 'type'=>$type, 'status'=>'open', 'title'=>$title,
                'summary'=>$summary, 'evidence'=>$evidence, 'started_at'=>now(),
            ]);
            $this->event($incident, $actorUserId, 'opened', $summary ?: $title, $evidence);
            return $incident->fresh('events');
        });
    }

    /** Handles note for the incident management service workflow. */
    public function note(IncidentRecord $incident, ?int $actorUserId, string $message, array $evidence = []): IncidentRecord
    {
        abort_if($incident->status === 'resolved', 409, 'Resolved incidents are immutable; open a follow-up incident instead.');
        $this->event($incident, $actorUserId, 'note', $message, $evidence);
        return $incident->fresh('events');
    }

    /** Handles status for the incident management service workflow. */
    public function status(IncidentRecord $incident, ?int $actorUserId, string $status, string $message): IncidentRecord
    {
        abort_unless(in_array($status, ['open','investigating','monitoring'], true), 422, 'Unsupported incident status.');
        abort_if($incident->status === 'resolved', 409, 'Resolved incidents are immutable.');
        DB::transaction(/** Inline callback for this operation. */ function () use ($incident,$actorUserId,$status,$message) {
            $incident->update(['status'=>$status]);
            $this->event($incident, $actorUserId, 'status_changed', $message, ['status'=>$status]);
        });
        return $incident->fresh('events');
    }

    /** Handles resolve for the incident management service workflow. */
    public function resolve(IncidentRecord $incident, ?int $actorUserId, string $summary, array $evidence = []): IncidentRecord
    {
        abort_if($incident->status === 'resolved', 409, 'Incident is already resolved.');
        DB::transaction(/** Inline callback for this operation. */ function () use ($incident,$actorUserId,$summary,$evidence) {
            $this->event($incident, $actorUserId, 'resolved', $summary, $evidence);
            $incident->update(['status'=>'resolved','resolved_at'=>now()]);
        });
        return $incident->fresh('events');
    }

    /** Handles event for the incident management service workflow. */
    private function event(IncidentRecord $incident, ?int $actorUserId, string $type, string $message, array $evidence): IncidentEvent
    {
        return IncidentEvent::query()->create([
            'public_id'=>(string) Str::ulid(), 'incident_record_id'=>$incident->id,
            'actor_user_id'=>$actorUserId, 'event_type'=>$type,
            'message'=>$message, 'evidence'=>$evidence, 'occurred_at'=>now(),
        ]);
    }
}
