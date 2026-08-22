<?php
namespace App\Http\Controllers\Api\V1;

use App\Domain\Operations\Services\IncidentManagementService;
use App\Domain\Operations\Services\LaunchGateService;
use App\Domain\Operations\Services\OperationalHealthService;
use App\Domain\Operations\Services\ProductionConfigurationAuditService;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\BackupRun;
use App\Models\DeploymentRun;
use App\Models\IncidentRecord;
use App\Models\LaunchGateRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Defines the AdminOperationsController class and its project responsibilities. */
class AdminOperationsController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(Request $r, OperationalHealthService $health, LaunchGateService $launch, ProductionConfigurationAuditService $configuration): JsonResponse
    {
        $this->admin($r);
        return response()->json(['data'=>[
            'health'=>$health->snapshot(true),
            'configuration'=>$configuration->audit(),
            'launchGate'=>$launch->evaluate(null,false),
            'backups'=>$this->backupRows(),
            'deployments'=>$this->deploymentRows(),
            'incidents'=>$this->incidentRows(),
            'failedJobs'=>$this->failedJobs(),
        ]]);
    }

    /** Handles backups for the admin operations controller workflow. */
    public function backups(Request $r): JsonResponse { $this->admin($r); return response()->json(['data'=>$this->backupRows()]); }
    /** Handles deployments for the admin operations controller workflow. */
    public function deployments(Request $r): JsonResponse { $this->admin($r); return response()->json(['data'=>$this->deploymentRows(50)]); }
    /** Handles incidents for the admin operations controller workflow. */
    public function incidents(Request $r): JsonResponse { $this->admin($r); return response()->json(['data'=>$this->incidentRows(100)]); }
    /** Handles configuration for the admin operations controller workflow. */
    public function configuration(Request $r, ProductionConfigurationAuditService $service): JsonResponse { $this->admin($r); return response()->json(['data'=>$service->audit()]); }

    /** Handles launch gate for the admin operations controller workflow. */
    public function launchGate(Request $r, LaunchGateService $launch): JsonResponse
    {
        $this->admin($r);
        $latest=Schema::hasTable('launch_gate_runs')?LaunchGateRun::query()->latest('ran_at')->first():null;
        return response()->json(['data'=>['current'=>$launch->evaluate(null,false),'latest'=>$latest?$this->gateRow($latest):null]]);
    }

    /** Handles run launch gate for the admin operations controller workflow. */
    public function runLaunchGate(Request $r, LaunchGateService $launch): JsonResponse
    {
        $this->admin($r);
        return response()->json(['data'=>$launch->evaluate((int)$r->user()->id,true)]);
    }

    /** Handles incident note for the admin operations controller workflow. */
    public function incidentNote(Request $r, IncidentRecord $incident, IncidentManagementService $service): JsonResponse
    {
        $this->admin($r);
        $d=$r->validate(['message'=>'required|string|max:5000','evidence'=>'nullable|array']);
        return response()->json(['data'=>$this->incidentRow($service->note($incident,(int)$r->user()->id,$d['message'],$d['evidence']??[]))]);
    }

    /** Handles incident status for the admin operations controller workflow. */
    public function incidentStatus(Request $r, IncidentRecord $incident, IncidentManagementService $service): JsonResponse
    {
        $this->admin($r);
        $d=$r->validate(['status'=>'required|in:open,investigating,monitoring','message'=>'required|string|max:5000']);
        return response()->json(['data'=>$this->incidentRow($service->status($incident,(int)$r->user()->id,$d['status'],$d['message']))]);
    }

    /** Handles incident resolve for the admin operations controller workflow. */
    public function incidentResolve(Request $r, IncidentRecord $incident, IncidentManagementService $service): JsonResponse
    {
        $this->admin($r);
        $d=$r->validate(['summary'=>'required|string|max:5000','evidence'=>'nullable|array']);
        return response()->json(['data'=>$this->incidentRow($service->resolve($incident,(int)$r->user()->id,$d['summary'],$d['evidence']??[]))]);
    }

    /** Handles gate row for the admin operations controller workflow. */
    private function gateRow(LaunchGateRun $run): array
    {
        return ['id'=>$run->public_id,'status'=>$run->status,'environment'=>$run->environment,'release'=>$run->release,'blockersCount'=>$run->blockers_count,'warningsCount'=>$run->warnings_count,'checks'=>$run->checks,'ranAt'=>$run->ran_at?->toIso8601String()];
    }

    /** Handles backup rows for the admin operations controller workflow. */
    private function backupRows(int $limit=30): array
    {
        if(!Schema::hasTable('backup_runs')) return [];
        return BackupRun::query()->latest('id')->limit($limit)->get()->map(/** Inline callback for this operation. */ fn($b)=>[
            'id'=>$b->public_id,'status'=>$b->status,'kind'=>$b->kind,'sizeBytes'=>$b->size_bytes,'sha256'=>$b->sha256,
            'startedAt'=>$b->started_at?->toIso8601String(),'completedAt'=>$b->completed_at?->toIso8601String(),
            'verifiedAt'=>$b->verified_at?->toIso8601String(),'expiresAt'=>$b->expires_at?->toIso8601String(),'error'=>$b->error_message,
        ])->all();
    }

    /** Handles deployment rows for the admin operations controller workflow. */
    private function deploymentRows(int $limit=30): array
    {
        if(!Schema::hasTable('deployment_runs')) return [];
        return DeploymentRun::query()->with('backupRun')->latest('started_at')->limit($limit)->get()->map(/** Inline callback for this operation. */ fn($d)=>[
            'id'=>$d->public_id,'environment'=>$d->environment,'release'=>$d->release,'previousRelease'=>$d->previous_release,
            'commitSha'=>$d->commit_sha,'artifactSha256'=>$d->artifact_sha256,'status'=>$d->status,'phase'=>$d->phase,
            'maintenanceUsed'=>$d->maintenance_used,'migrationBatchBefore'=>$d->migration_batch_before,'migrationBatchAfter'=>$d->migration_batch_after,
            'backupId'=>$d->backupRun?->public_id,'backupVerifiedAt'=>$d->backupRun?->verified_at?->toIso8601String(),
            'failureReason'=>$d->failure_reason,'startedAt'=>$d->started_at?->toIso8601String(),'completedAt'=>$d->completed_at?->toIso8601String(),
        ])->all();
    }

    /** Handles incident rows for the admin operations controller workflow. */
    private function incidentRows(int $limit=30): array
    {
        if(!Schema::hasTable('incident_records')) return [];
        return IncidentRecord::query()->with(['events'=>/** Inline callback for this operation. */ fn($q)=>$q->latest('occurred_at')->limit(20)])->latest('started_at')->limit($limit)->get()->map(/** Inline callback for this operation. */ fn($i)=>$this->incidentRow($i))->all();
    }

    /** Handles incident row for the admin operations controller workflow. */
    private function incidentRow(IncidentRecord $i): array
    {
        $events=$i->relationLoaded('events')?$i->events:collect();
        return [
            'id'=>$i->public_id,'severity'=>$i->severity,'type'=>$i->type,'status'=>$i->status,'title'=>$i->title,'summary'=>$i->summary,
            'startedAt'=>$i->started_at?->toIso8601String(),'resolvedAt'=>$i->resolved_at?->toIso8601String(),
            'events'=>$events->map(/** Inline callback for this operation. */ fn($e)=>['id'=>$e->public_id,'type'=>$e->event_type,'message'=>$e->message,'evidence'=>$e->evidence,'occurredAt'=>$e->occurred_at?->toIso8601String()])->values()->all(),
        ];
    }

    /** Handles failed jobs for the admin operations controller workflow. */
    private function failedJobs(): array
    {
        if(!Schema::hasTable('failed_jobs')) return [];
        return DB::table('failed_jobs')->latest('id')->limit(30)->get(['uuid','connection','queue','failed_at'])->map(/** Inline callback for this operation. */ fn($j)=>['id'=>$j->uuid,'connection'=>$j->connection,'queue'=>$j->queue,'failedAt'=>$j->failed_at])->all();
    }

    /** Handles admin for the admin operations controller workflow. */
    private function admin(Request $r): void
    {
        $role=$r->user()?->role;
        $v=$role instanceof UserRole?$role->value:(string)$role;
        abort_unless(in_array($v,[UserRole::Admin->value,UserRole::SuperAdmin->value],true),403);
    }
}
