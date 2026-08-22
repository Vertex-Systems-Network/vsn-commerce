<?php
namespace App\Http\Controllers\Api\V1;

use App\Domain\Operations\Services\IncidentManagementService;
use App\Domain\Operations\Services\ProductionAcceptanceService;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\BackupRun;
use App\Models\DisasterRecoveryDrill;
use App\Models\IncidentRecord;
use App\Models\ProductionAcceptanceRun;
use App\Models\ReleaseCandidateManifest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Defines the AdminAcceptanceController class and its project responsibilities. */
class AdminAcceptanceController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(Request $r,ProductionAcceptanceService $acceptance):JsonResponse
    {
        $this->allowed($r,[UserRole::Finance->value,UserRole::Admin->value,UserRole::SuperAdmin->value]);
        $latest=Schema::hasTable('production_acceptance_runs')?ProductionAcceptanceRun::query()->with(['signoffs','releaseCandidateManifest'])->latest('id')->first():null;$manifest=Schema::hasTable('release_candidate_manifests')?ReleaseCandidateManifest::query()->latest('sealed_at')->first():null;
        return response()->json(['data'=>['current'=>$acceptance->evaluate(null,false),'goLive'=>$acceptance->goLiveStatus(),'latest'=>$latest?$acceptance->row($latest):null,'drills'=>DisasterRecoveryDrill::query()->latest('completed_at')->limit(10)->get()->map(/** Inline callback for this operation. */ fn($d)=>$this->drillRow($d))->all(),'incidents'=>IncidentRecord::query()->latest('started_at')->limit(30)->get()->map(/** Inline callback for this operation. */ fn($i)=>$this->incidentRow($i))->all(),'releaseCandidate'=>$manifest?$acceptance->manifestRow($manifest):null]]);
    }
    /** Executes the admin acceptance controller operation. */
    public function run(Request $r,ProductionAcceptanceService $acceptance):JsonResponse{$this->allowed($r,[UserRole::Admin->value,UserRole::SuperAdmin->value]);return response()->json(['data'=>$acceptance->evaluate((int)$r->user()->id,true)]);}
    /** Handles signoff for the admin acceptance controller workflow. */
    public function signoff(Request $r,ProductionAcceptanceRun $acceptanceRun,ProductionAcceptanceService $acceptance):JsonResponse{$data=$r->validate(['area'=>'required|string|max:40','decision'=>'required|in:approved,rejected','comment'=>'nullable|string|max:2000']);return response()->json(['data'=>$acceptance->sign($acceptanceRun,$r->user(),$data['area'],$data['decision'],$data['comment']??null)]);}
    /** Handles seal for the admin acceptance controller workflow. */
    public function seal(Request $r,ProductionAcceptanceRun $acceptanceRun,ProductionAcceptanceService $acceptance):JsonResponse{$this->allowed($r,[UserRole::SuperAdmin->value]);return response()->json(['data'=>$acceptance->seal($acceptanceRun,$r->user())],201);}
    /** Handles record drill for the admin acceptance controller workflow. */
    public function recordDrill(Request $r):JsonResponse{$this->allowed($r,[UserRole::Admin->value,UserRole::SuperAdmin->value]);$d=$r->validate(['status'=>'required|in:passed,failed','rto_minutes'=>'required|integer|min:0|max:10080','rpo_minutes'=>'required|integer|min:0|max:10080','backup_id'=>'nullable|string|max:40','backup_sha256'=>['nullable','string','regex:/^[a-fA-F0-9]{64}$/'],'evidence'=>'nullable|array']);$backup=null;if(!empty($d['backup_id']))$backup=BackupRun::query()->where('public_id',$d['backup_id'])->firstOrFail();$row=DisasterRecoveryDrill::query()->create(['public_id'=>(string)Str::ulid(),'actor_user_id'=>$r->user()->id,'backup_run_id'=>$backup?->id,'status'=>$d['status'],'rto_minutes'=>$d['rto_minutes'],'rpo_minutes'=>$d['rpo_minutes'],'backup_sha256'=>$backup?->sha256??($d['backup_sha256']??null),'evidence'=>$d['evidence']??[],'started_at'=>now()->subMinutes((int)$d['rto_minutes']),'completed_at'=>now()]);return response()->json(['data'=>$this->drillRow($row)],201);}
    /** Handles create incident for the admin acceptance controller workflow. */
    public function createIncident(Request $r,IncidentManagementService $service):JsonResponse{$this->allowed($r,[UserRole::Admin->value,UserRole::SuperAdmin->value]);$d=$r->validate(['severity'=>'required|in:sev1,sev2,sev3,sev4','type'=>'required|string|max:40','title'=>'required|string|max:180','summary'=>'nullable|string|max:5000','evidence'=>'nullable|array']);$i=$service->open((int)$r->user()->id,$d['severity'],$d['type'],$d['title'],$d['summary']??null,$d['evidence']??[]);return response()->json(['data'=>$this->incidentRow($i)],201);}
    /** Handles resolve incident for the admin acceptance controller workflow. */
    public function resolveIncident(Request $r,IncidentRecord $incident,IncidentManagementService $service):JsonResponse{$this->allowed($r,[UserRole::Admin->value,UserRole::SuperAdmin->value]);$d=$r->validate(['summary'=>'required|string|max:5000','evidence'=>'nullable|array']);return response()->json(['data'=>$this->incidentRow($service->resolve($incident,(int)$r->user()->id,$d['summary'],$d['evidence']??[]))]);}
    /** Handles drill row for the admin acceptance controller workflow. */
    private function drillRow(DisasterRecoveryDrill $d):array{return ['id'=>$d->public_id,'status'=>$d->status,'rtoMinutes'=>$d->rto_minutes,'rpoMinutes'=>$d->rpo_minutes,'backupSha256'=>$d->backup_sha256,'completedAt'=>$d->completed_at?->toIso8601String()];}
    /** Handles incident row for the admin acceptance controller workflow. */
    private function incidentRow(IncidentRecord $i):array{return ['id'=>$i->public_id,'severity'=>$i->severity,'type'=>$i->type,'status'=>$i->status,'title'=>$i->title,'summary'=>$i->summary,'startedAt'=>$i->started_at?->toIso8601String(),'resolvedAt'=>$i->resolved_at?->toIso8601String()];}
    /** Handles allowed for the admin acceptance controller workflow. */
    private function allowed(Request $r,array $roles):void{$role=$r->user()?->role;$v=$role instanceof UserRole?$role->value:(string)$role;abort_unless(in_array($v,$roles,true),403);}
}
