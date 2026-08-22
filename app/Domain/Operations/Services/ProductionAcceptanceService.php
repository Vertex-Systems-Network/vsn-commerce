<?php
namespace App\Domain\Operations\Services;

use App\Enums\UserRole;
use App\Models\DeploymentRun;
use App\Models\DisasterRecoveryDrill;
use App\Models\IncidentRecord;
use App\Models\ProductionAcceptanceRun;
use App\Models\ProductionAcceptanceSignoff;
use App\Models\ReleaseCandidateManifest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Defines the ProductionAcceptanceService class and its project responsibilities. */
class ProductionAcceptanceService
{
    /** Initializes the ProductionAcceptanceService instance and its dependencies. */
    public function __construct(private readonly LaunchGateService $launchGate){}

    /** Handles evaluate for the production acceptance service workflow. */
    public function evaluate(?int $actorUserId=null,bool $persist=false):array
    {
        $checks=[];$production=app()->isProduction();
        $release=(string)config('vsn.operations.release','unknown');
        $environment=app()->environment();

        $launch=$this->launchGate->evaluate(null,false);
        $this->check($checks,'launch_gate',(bool)$launch['ready'],'block','Technical launch gate must have zero blockers.',['blockers'=>$launch['blockersCount'],'warnings'=>$launch['warningsCount']]);
        $this->check($checks,'release_identity',$release!==''&&$release!=='unknown',$production?'block':'warning','Final acceptance must identify a concrete release.',['release'=>$release]);

        $deployment=$this->deploymentCheck($checks,$release,$environment,$production);
        $locks=$this->dependencyLockCheck($checks,$deployment,$production);
        $verification=$this->runtimeVerificationCheck($checks,$release,$deployment?->artifact_sha256,$production);
        $this->drCheck($checks,$production);
        $this->incidentCheck($checks);
        $this->privacySecurityChecks($checks,$production);
        $this->runbookChecks($checks);

        $blockers=collect($checks)->where('status','block')->count();
        $warnings=collect($checks)->where('status','warning')->count();
        $artifactSha=$deployment?->artifact_sha256;
        $verificationSha=$verification['sha256']??null;
        $evidenceSha=$this->digest([
            'release'=>$release,'environment'=>$environment,'artifactSha256'=>$artifactSha,
            'verificationSha256'=>$verificationSha,'composerLockSha256'=>$locks['composer']??null,'npmLockSha256'=>$locks['npm']??null,'deploymentId'=>$deployment?->public_id,'checks'=>$checks,
        ]);

        $report=[
            'status'=>$blockers?'blocked':'awaiting_signoff','release'=>$release,'environment'=>$environment,
            'deploymentId'=>$deployment?->public_id,'artifactSha256'=>$artifactSha,'composerLockSha256'=>$locks['composer']??null,'npmLockSha256'=>$locks['npm']??null,'verificationSha256'=>$verificationSha,
            'evidenceSha256'=>$evidenceSha,'blockersCount'=>$blockers,'warningsCount'=>$warnings,
            'checkedAt'=>now()->toIso8601String(),'checks'=>$checks,'requiredSignoffs'=>$this->requiredSignoffs(),
            'distinctSignersRequired'=>(bool)config('vsn.acceptance.require_distinct_signers',false),
        ];

        if($persist&&Schema::hasTable('production_acceptance_runs')){
            $run=ProductionAcceptanceRun::query()->create([
                'public_id'=>(string)Str::ulid(),'actor_user_id'=>$actorUserId,'deployment_run_id'=>$deployment?->id,
                'release'=>$release,'environment'=>$environment,'artifact_sha256'=>$artifactSha,
                'composer_lock_sha256'=>$locks['composer']??null,'npm_lock_sha256'=>$locks['npm']??null,
                'verification_sha256'=>$verificationSha,'evidence_sha256'=>$evidenceSha,
                'status'=>$report['status'],'blockers_count'=>$blockers,'warnings_count'=>$warnings,
                'checks'=>$checks,'evaluated_at'=>now(),
            ]);
            $report['id']=$run->public_id;
        }
        return $report;
    }

    /** Handles go live status for the production acceptance service workflow. */
    public function goLiveStatus():array
    {
        $current=$this->evaluate(null,false);
        $minutes=max(5,(int)config('vsn.acceptance.acceptance_valid_minutes',120));
        $latest=Schema::hasTable('production_acceptance_runs')?ProductionAcceptanceRun::query()
            ->with(['signoffs','releaseCandidateManifest'])
            ->where('status','approved')->where('release',(string)config('vsn.operations.release','unknown'))
            ->where('environment',app()->environment())->latest('approved_at')->first():null;
        $fresh=$latest?->approved_at?->gte(now()->subMinutes($minutes))??false;
        $signed=$latest&&collect($this->requiredSignoffs())->every(/** Inline callback for this operation. */ fn($a)=>$latest->signoffs->contains(/** Inline callback for this operation. */ fn($x)=>$x->area===$a&&$x->decision==='approved'));
        $seal=$latest?->releaseCandidateManifest;
        $lockSealMatches=!app()->isProduction()||($seal&&$seal->composer_lock_sha256===$current['composerLockSha256']&&$seal->npm_lock_sha256===$current['npmLockSha256']);
        $sealed=$seal&&$seal->artifact_sha256===$current['artifactSha256']&&$lockSealMatches&&$seal->verification_sha256===$current['verificationSha256']&&$seal->acceptance_evidence_sha256===$latest->evidence_sha256;
        return [
            'ready'=>$current['blockersCount']===0&&$fresh&&$signed&&$sealed,
            'currentBlockers'=>$current['blockersCount'],'acceptanceFresh'=>(bool)$fresh,'allSignoffs'=>(bool)$signed,
            'releaseCandidateSealed'=>(bool)$sealed,'validMinutes'=>$minutes,
            'acceptance'=>$latest?$this->row($latest):null,'releaseCandidate'=>$seal?$this->manifestRow($seal):null,
            'checkedAt'=>now()->toIso8601String(),
        ];
    }

    /** Handles sign for the production acceptance service workflow. */
    public function sign(ProductionAcceptanceRun $run,User $user,string $area,string $decision,?string $comment=null):array
    {
        abort_unless(in_array($area,$this->requiredSignoffs(),true),422,'Unknown acceptance area.');
        abort_unless(in_array($decision,['approved','rejected'],true),422,'Decision must be approved or rejected.');
        $this->authorizeArea($user,$area);
        if($run->blockers_count>0) throw ValidationException::withMessages(['acceptance'=>['Blocking checks must be cleared before sign-off.']]);
        abort_unless($run->status==='awaiting_signoff',409,'Only the current awaiting-signoff acceptance run can be signed.');

        $current=$this->evaluate(null,false);
        if($current['blockersCount']>0) throw ValidationException::withMessages(['acceptance'=>['Current production evidence has blocking checks; create a new acceptance run after remediation.']]);
        $lockMatch=!app()->isProduction()&&$run->composer_lock_sha256===null&&$run->npm_lock_sha256===null
            ? true
            : $run->composer_lock_sha256===$current['composerLockSha256']&&$run->npm_lock_sha256===$current['npmLockSha256'];
        if($run->release!==$current['release']||$run->environment!==$current['environment']||$run->artifact_sha256!==$current['artifactSha256']||!$lockMatch||$run->verification_sha256!==$current['verificationSha256']){
            throw ValidationException::withMessages(['acceptance'=>['Release candidate evidence changed after this acceptance snapshot. Create a new acceptance run.']]);
        }
        if((bool)config('vsn.acceptance.require_distinct_signers',false)&&$run->signoffs()->where('signed_by_user_id',$user->id)->exists()){
            throw ValidationException::withMessages(['signoff'=>['Production acceptance requires a different authorized signer for each area.']]);
        }

        ProductionAcceptanceSignoff::query()->create([
            'acceptance_run_id'=>$run->id,'area'=>$area,'signed_by_user_id'=>$user->id,'decision'=>$decision,'comment'=>$comment,
            'evidence'=>['release'=>$run->release,'environment'=>$run->environment,'artifactSha256'=>$run->artifact_sha256,'composerLockSha256'=>$run->composer_lock_sha256,'npmLockSha256'=>$run->npm_lock_sha256,'verificationSha256'=>$run->verification_sha256,'acceptanceEvidenceSha256'=>$run->evidence_sha256],
            'signed_at'=>now(),
        ]);
        $signoffs=$run->signoffs()->get();
        if($signoffs->contains('decision','rejected')){$run->forceFill(['status'=>'rejected'])->save();}
        elseif(collect($this->requiredSignoffs())->every(/** Inline callback for this operation. */ fn($a)=>$signoffs->contains(/** Inline callback for this operation. */ fn($s)=>$s->area===$a&&$s->decision==='approved'))){$run->forceFill(['status'=>'approved','approved_at'=>now()])->save();}
        return $this->row($run->fresh('signoffs'));
    }

    /** Handles seal for the production acceptance service workflow. */
    public function seal(ProductionAcceptanceRun $run,?User $user=null):array
    {
        if($user){$role=$user->role instanceof UserRole?$user->role->value:(string)$user->role;abort_unless($role===UserRole::SuperAdmin->value,403);}
        $run->loadMissing(['signoffs','releaseCandidateManifest','deploymentRun']);
        if($run->releaseCandidateManifest)return $this->manifestRow($run->releaseCandidateManifest);
        abort_unless($run->status==='approved'&&$run->approved_at,409,'Only a fully approved acceptance run can be sealed.');
        $minutes=max(5,(int)config('vsn.acceptance.acceptance_valid_minutes',120));
        abort_unless($run->approved_at->gte(now()->subMinutes($minutes)),409,'Acceptance approval is stale; create a new acceptance run.');
        $signed=collect($this->requiredSignoffs())->every(/** Inline callback for this operation. */ fn($a)=>$run->signoffs->contains(/** Inline callback for this operation. */ fn($s)=>$s->area===$a&&$s->decision==='approved'));
        abort_unless($signed,409,'All required acceptance sign-offs must be approved before sealing.');
        $current=$this->evaluate(null,false);
        abort_if($current['blockersCount']>0,409,'Current production evidence is blocked.');
        $sealLocksMatch=!app()->isProduction()||($run->composer_lock_sha256===$current['composerLockSha256']&&$run->npm_lock_sha256===$current['npmLockSha256']);
        abort_unless($run->release===$current['release']&&$run->environment===$current['environment']&&$run->artifact_sha256===$current['artifactSha256']&&$sealLocksMatch&&$run->verification_sha256===$current['verificationSha256'],409,'Current release evidence no longer matches the approved acceptance snapshot.');
        $requiredHashes=$run->artifact_sha256&&$run->verification_sha256&&$run->evidence_sha256&&(!app()->isProduction()||($run->composer_lock_sha256&&$run->npm_lock_sha256));
        abort_unless($requiredHashes,409,'Acceptance run is missing final release hashes.');

        $sealedAt=now();
        $signoffs=$run->signoffs->sortBy('area')->map(/** Inline callback for this operation. */ fn($s)=>[
            'area'=>$s->area,'decision'=>$s->decision,'signedBy'=>$s->signed_by_user_id,'signedAt'=>$s->signed_at?->toIso8601String(),
        ])->values()->all();
        $evidence=[
            'schema'=>'vsn-final-release-candidate-v1','release'=>$run->release,'environment'=>$run->environment,
            'deploymentId'=>$run->deploymentRun?->public_id,'acceptanceRunId'=>$run->public_id,
            'artifactSha256'=>$run->artifact_sha256,'composerLockSha256'=>$run->composer_lock_sha256,'npmLockSha256'=>$run->npm_lock_sha256,'verificationSha256'=>$run->verification_sha256,
            'acceptanceEvidenceSha256'=>$run->evidence_sha256,'signoffs'=>$signoffs,'sealedAt'=>$sealedAt->toIso8601String(),
        ];
        $manifestSha=$this->digest($evidence);
        $manifest=ReleaseCandidateManifest::query()->create([
            'public_id'=>(string)Str::ulid(),'acceptance_run_id'=>$run->id,'deployment_run_id'=>$run->deployment_run_id,
            'sealed_by_user_id'=>$user?->id,'release'=>$run->release,'environment'=>$run->environment,
            'artifact_sha256'=>$run->artifact_sha256,'composer_lock_sha256'=>$run->composer_lock_sha256,'npm_lock_sha256'=>$run->npm_lock_sha256,'verification_sha256'=>$run->verification_sha256,
            'acceptance_evidence_sha256'=>$run->evidence_sha256,'manifest_sha256'=>$manifestSha,'evidence'=>$evidence,'sealed_at'=>$sealedAt,
        ]);
        $this->writeManifestFile($manifest);
        return $this->manifestRow($manifest);
    }

    /** Handles row for the production acceptance service workflow. */
    public function row(ProductionAcceptanceRun $run):array
    {
        $run->loadMissing(['signoffs','releaseCandidateManifest','deploymentRun']);
        return [
            'id'=>$run->public_id,'release'=>$run->release,'environment'=>$run->environment,'status'=>$run->status,
            'deploymentId'=>$run->deploymentRun?->public_id,'artifactSha256'=>$run->artifact_sha256,'composerLockSha256'=>$run->composer_lock_sha256,'npmLockSha256'=>$run->npm_lock_sha256,'verificationSha256'=>$run->verification_sha256,'evidenceSha256'=>$run->evidence_sha256,
            'blockersCount'=>$run->blockers_count,'warningsCount'=>$run->warnings_count,'checks'=>$run->checks,
            'evaluatedAt'=>$run->evaluated_at?->toIso8601String(),'approvedAt'=>$run->approved_at?->toIso8601String(),
            'signoffs'=>$run->signoffs->map(/** Inline callback for this operation. */ fn($s)=>['area'=>$s->area,'decision'=>$s->decision,'signedBy'=>$s->signed_by_user_id,'comment'=>$s->comment,'signedAt'=>$s->signed_at?->toIso8601String()])->all(),
            'releaseCandidate'=>$run->releaseCandidateManifest?$this->manifestRow($run->releaseCandidateManifest):null,
        ];
    }

    /** Handles manifest row for the production acceptance service workflow. */
    public function manifestRow(ReleaseCandidateManifest $manifest):array
    {
        $manifest->loadMissing(['acceptanceRun','deploymentRun']);
        return [
            'id'=>$manifest->public_id,'release'=>$manifest->release,'environment'=>$manifest->environment,
            'acceptanceRunId'=>$manifest->acceptanceRun?->public_id,'deploymentId'=>$manifest->deploymentRun?->public_id,
            'artifactSha256'=>$manifest->artifact_sha256,'composerLockSha256'=>$manifest->composer_lock_sha256,'npmLockSha256'=>$manifest->npm_lock_sha256,'verificationSha256'=>$manifest->verification_sha256,
            'acceptanceEvidenceSha256'=>$manifest->acceptance_evidence_sha256,'manifestSha256'=>$manifest->manifest_sha256,
            'sealedBy'=>$manifest->sealed_by_user_id,'sealedAt'=>$manifest->sealed_at?->toIso8601String(),
        ];
    }

    /** Handles required signoffs for the production acceptance service workflow. */
    public function requiredSignoffs():array{return ['operations','security_privacy','finance','business_owner'];}

    /** Handles deployment check for the production acceptance service workflow. */
    private function deploymentCheck(array &$checks,string $release,string $environment,bool $production):?DeploymentRun
    {
        if(!Schema::hasTable('deployment_runs')){$this->check($checks,'deployment_evidence',!$production,$production?'block':'warning','Deployment evidence table is unavailable.');return null;}
        $run=DeploymentRun::query()->with('backupRun')->where('release',$release)->where('environment',$environment)->where('status','completed')->latest('completed_at')->first();
        $artifactOk=$run&&preg_match('/^[a-f0-9]{64}$/',(string)$run->artifact_sha256);
        $backupOk=$run?->backupRun&&$run->backupRun->status==='completed'&&$run->backupRun->verified_at;
        $ok=(bool)($run&&$artifactOk&&$backupOk&&$run->phase==='complete'&&$run->migration_batch_after!==null);
        $this->check($checks,'deployment_evidence',$ok,$production?'block':'warning','Final acceptance requires a completed deployment for this exact release with artifact hash, verified pre-migration backup and migration evidence.',[
            'deploymentId'=>$run?->public_id,'artifactSha256'=>$run?->artifact_sha256,'backupId'=>$run?->backupRun?->public_id,'backupVerifiedAt'=>$run?->backupRun?->verified_at?->toIso8601String(),'completedAt'=>$run?->completed_at?->toIso8601String(),
        ]);
        return $run;
    }

    /** Handles dependency lock check for the production acceptance service workflow. */
    private function dependencyLockCheck(array &$checks,?DeploymentRun $deployment,bool $production):array
    {
        $composer=File::exists(base_path('composer.lock'))?hash_file('sha256',base_path('composer.lock')):null;
        $npm=File::exists(base_path('package-lock.json'))?hash_file('sha256',base_path('package-lock.json')):null;
        $format=/** Inline callback for this operation. */ fn($v)=>is_string($v)&&preg_match('/^[a-f0-9]{64}$/',$v);
        $deploymentComposer=$deployment?->composer_lock_sha256;$deploymentNpm=$deployment?->npm_lock_sha256;
        $ok=$format($composer)&&$format($npm)&&$deployment&&$deploymentComposer&&$deploymentNpm&&hash_equals($deploymentComposer,$composer)&&hash_equals($deploymentNpm,$npm);
        $this->check($checks,'dependency_locks',$ok,$production?'block':'warning','Final acceptance requires committed dependency locks whose SHA-256 fingerprints match the deployed release.',[
            'composerLockPresent'=>(bool)$composer,'composerLockSha256'=>$composer,'deploymentComposerLockSha256'=>$deploymentComposer,'npmLockSha256'=>$npm,'deploymentNpmLockSha256'=>$deploymentNpm,
        ]);
        return ['composer'=>$composer,'npm'=>$npm,'ok'=>$ok];
    }

    /** Handles runtime verification check for the production acceptance service workflow. */
    private function runtimeVerificationCheck(array &$checks,string $release,?string $artifactSha,bool $production):array
    {
        $path=(string)config('vsn.acceptance.verification_manifest');
        $required=(array)config('vsn.acceptance.required_runtime_flags',[]);
        if(!File::exists($path)){$this->check($checks,'final_runtime_evidence',!$production,$production?'block':'warning','Final runtime verification manifest is missing.',['path'=>basename($path)]);return ['sha256'=>null];}
        $raw=(string)File::get($path);$json=json_decode($raw,true);$valid=is_array($json);
        $missing=$valid?collect($required)->filter(/** Inline callback for this operation. */ fn($key)=>($json[$key]??false)!==true)->values()->all():$required;
        $manifestRelease=(string)($json['release']??'');$releaseMatch=$manifestRelease===$release;$manifestArtifact=(string)($json['artifactSha256']??'');$artifactMatch=$artifactSha!==null&&$manifestArtifact===$artifactSha;
        $maxAge=max(5,(int)config('vsn.acceptance.runtime_evidence_max_age_minutes',180));
        $generated=null;try{$generated=!empty($json['generatedAt'])?Carbon::parse($json['generatedAt']):null;}catch(\Throwable){}
        $fresh=$generated&&$generated->gte(now()->subMinutes($maxAge));
        $ok=$valid&&$missing===[]&&$releaseMatch&&$artifactMatch&&$fresh;
        $sha=hash('sha256',$raw);
        $this->check($checks,'final_runtime_evidence',$ok,$production?'block':'warning','Final runtime evidence must match this release, be fresh, and include all required automated workflow/build/security flags.',[
            'sha256'=>$sha,'manifestRelease'=>$manifestRelease,'releaseMatch'=>$releaseMatch,'manifestArtifactSha256'=>$manifestArtifact,'artifactMatch'=>$artifactMatch,'missing'=>$missing,'generatedAt'=>$generated?->toIso8601String(),'maxAgeMinutes'=>$maxAge,
        ]);
        return ['sha256'=>$sha,'json'=>$json,'missing'=>$missing,'fresh'=>(bool)$fresh,'releaseMatch'=>$releaseMatch,'artifactMatch'=>$artifactMatch];
    }

    /** Handles authorize area for the production acceptance service workflow. */
    private function authorizeArea(User $user,string $area):void{$r=$user->role instanceof UserRole?$user->role->value:(string)$user->role;$allowed=match($area){'finance'=>[UserRole::Finance->value,UserRole::Admin->value,UserRole::SuperAdmin->value],'business_owner'=>[UserRole::SuperAdmin->value],default=>[UserRole::Admin->value,UserRole::SuperAdmin->value]};abort_unless(in_array($r,$allowed,true),403);}
    /** Handles dr check for the production acceptance service workflow. */
    private function drCheck(array &$checks,bool $production):void{$maxAge=max(1,(int)config('vsn.acceptance.drill_max_age_days',90));$rto=(int)config('vsn.acceptance.rto_target_minutes',60);$rpo=(int)config('vsn.acceptance.rpo_target_minutes',1440);$d=Schema::hasTable('disaster_recovery_drills')?DisasterRecoveryDrill::query()->where('status','passed')->latest('completed_at')->first():null;$ok=$d&&$d->completed_at?->gte(now()->subDays($maxAge))&&$d->rto_minutes!==null&&$d->rpo_minutes!==null&&$d->rto_minutes<=$rto&&$d->rpo_minutes<=$rpo;$this->check($checks,'disaster_recovery_drill',(bool)$ok,$production?'block':'warning','A recent successful restore drill must meet the configured RTO/RPO targets.',['maxAgeDays'=>$maxAge,'targetRtoMinutes'=>$rto,'targetRpoMinutes'=>$rpo,'lastCompletedAt'=>$d?->completed_at?->toIso8601String(),'rtoMinutes'=>$d?->rto_minutes,'rpoMinutes'=>$d?->rpo_minutes]);}
    /** Handles incident check for the production acceptance service workflow. */
    private function incidentCheck(array &$checks):void{$open=Schema::hasTable('incident_records')?IncidentRecord::query()->whereIn('severity',['sev1','sev2'])->whereNotIn('status',['resolved','closed'])->count():0;$this->check($checks,'critical_incidents',$open===0,'block','No unresolved SEV1/SEV2 incident may remain at production acceptance.',['open'=>$open]);}
    /** Handles privacy security checks for the production acceptance service workflow. */
    private function privacySecurityChecks(array &$checks,bool $production):void{$private=[];foreach(['kyc_documents'=>config('vsn.kyc.document_disk'),'message_attachments'=>config('vsn.messaging.attachment_disk'),'report_exports'=>config('vsn.reporting.export_disk')] as $label=>$disk){$visibility=config("filesystems.disks.{$disk}.visibility");$ok=$disk&&$visibility!=='public';$private[$label]=['disk'=>$disk,'private'=>$ok];}$backup=(string)config('vsn.operations.backups.disk','local');$private['backups']=['disk'=>$backup,'private'=>config("filesystems.disks.{$backup}.visibility")!=='public'];$this->check($checks,'private_sensitive_storage',collect($private)->every(/** Inline callback for this operation. */ fn($x)=>$x['private']),$production?'block':'warning','KYC, messages, reports and backups must use non-public storage.',$private);$views=(int)config('vsn.catalog.product_view_retention_days',180);$reports=(int)config('vsn.reporting.export_retention_days',14);$retention=$views>0&&$views<=(int)config('vsn.acceptance.max_product_view_retention_days',365)&&$reports>0&&$reports<=(int)config('vsn.acceptance.max_report_retention_days',30);$this->check($checks,'privacy_retention',$retention,$production?'block':'warning','Behavioral analytics and private exports must have bounded retention.',['productViewDays'=>$views,'reportExportDays'=>$reports]);}
    /** Handles runbook checks for the production acceptance service workflow. */
    private function runbookChecks(array &$checks):void{$required=(array)config('vsn.acceptance.required_runbooks',[]);$missing=collect($required)->filter(/** Inline callback for this operation. */ fn($p)=>!File::exists(base_path((string)$p)))->values()->all();$this->check($checks,'operator_runbooks',$missing===[],'block','Required incident, recovery and go-live runbooks must ship with the release.',['missing'=>$missing]);}
    /** Handles check for the production acceptance service workflow. */
    private function check(array &$checks,string $code,bool $ok,string $failure,string $message,array $details=[]):void{$checks[]=['code'=>$code,'status'=>$ok?'pass':$failure,'message'=>$message,'details'=>$details];}

    /** Handles digest for the production acceptance service workflow. */
    private function digest(array $value):string{return hash('sha256',json_encode($this->canonical($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}
    /** Handles canonical for the production acceptance service workflow. */
    private function canonical(mixed $value):mixed
    {
        if(!is_array($value))return $value;
        if(array_is_list($value))return array_map(/** Inline callback for this operation. */ fn($v)=>$this->canonical($v),$value);
        ksort($value);foreach($value as $k=>$v)$value[$k]=$this->canonical($v);return $value;
    }
    /** Handles write manifest file for the production acceptance service workflow. */
    private function writeManifestFile(ReleaseCandidateManifest $manifest):void
    {
        $path=(string)config('vsn.acceptance.release_candidate_manifest_path',base_path('runtime/final-release-candidate.json'));
        $dir=dirname($path);if(!File::isDirectory($dir))File::makeDirectory($dir,0750,true);
        $row=$this->manifestRow($manifest);$row['evidence']=$manifest->evidence;
        File::put($path,json_encode($row,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL);
    }
}
