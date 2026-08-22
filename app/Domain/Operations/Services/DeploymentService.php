<?php
namespace App\Domain\Operations\Services;

use App\Models\BackupRun;
use App\Models\DeploymentRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Defines the DeploymentService class and its project responsibilities. */
class DeploymentService
{
    /** Handles begin for the deployment service workflow. */
    public function begin(array $data, ?int $actorUserId = null): DeploymentRun
    {
        $release = trim((string) ($data['release'] ?? config('vsn.operations.release', 'unknown')));
        if ($release === '' || $release === 'unknown') throw new \InvalidArgumentException('A concrete release identifier is required.');
        foreach (['commit_sha'=>40,'artifact_sha256'=>64,'composer_lock_sha256'=>64,'npm_lock_sha256'=>64] as $key=>$len) {
            $value = strtolower(trim((string)($data[$key] ?? '')));
            if ($value !== '' && ! preg_match('/^[a-f0-9]{'.$len.'}$/', $value)) throw new \InvalidArgumentException("{$key} must be {$len} lowercase hex characters.");
        }
        if (app()->isProduction() && config('vsn.operations.deployment.require_artifact_sha', true) && blank($data['artifact_sha256'] ?? null)) {
            throw new \InvalidArgumentException('Production deployment requires artifact SHA-256 evidence.');
        }
        if (app()->isProduction() && config('vsn.operations.deployment.require_dependency_locks', true) && (blank($data['composer_lock_sha256'] ?? null) || blank($data['npm_lock_sha256'] ?? null))) {
            throw new \InvalidArgumentException('Production deployment requires Composer and npm lock SHA-256 evidence.');
        }
        $backupId=$data['backup_run_id'] ?? null;
        if (app()->isProduction() && config('vsn.operations.deployment.require_verified_backup', true) && ! $backupId) {
            throw new \InvalidArgumentException('Production deployment requires a verified pre-migration backup.');
        }
        if ($backupId) {
            $backup=BackupRun::query()->findOrFail($backupId);
            if (config('vsn.operations.deployment.require_verified_backup', true) && !($backup->status==='completed' && $backup->verified_at)) {
                throw new \InvalidArgumentException('Deployment backup must be completed and checksum-verified.');
            }
        }
        return DeploymentRun::query()->create([
            'public_id'=>(string)Str::ulid(), 'actor_user_id'=>$actorUserId,
            'backup_run_id'=>$data['backup_run_id'] ?? null, 'environment'=>app()->environment(),
            'release'=>$release, 'previous_release'=>$data['previous_release'] ?? null,
            'commit_sha'=>filled($data['commit_sha'] ?? null)?strtolower($data['commit_sha']):null,
            'artifact_sha256'=>filled($data['artifact_sha256'] ?? null)?strtolower($data['artifact_sha256']):null,
            'composer_lock_sha256'=>filled($data['composer_lock_sha256'] ?? null)?strtolower($data['composer_lock_sha256']):null,
            'npm_lock_sha256'=>filled($data['npm_lock_sha256'] ?? null)?strtolower($data['npm_lock_sha256']):null,
            'status'=>'running', 'phase'=>'preflight',
            'migration_batch_before'=>$this->migrationBatch(),
            'maintenance_used'=>(bool)($data['maintenance_used'] ?? false),
            'evidence'=>$data['evidence'] ?? [], 'started_at'=>now(),
        ]);
    }

    /** Handles phase for the deployment service workflow. */
    public function phase(DeploymentRun $run, string $phase, array $evidence = []): DeploymentRun
    {
        abort_if(in_array($run->status,['completed','failed','rolled_back'],true),409,'Deployment is already terminal.');
        $allowed=['preflight','backup','dependencies','build','migrate','switch','restart','readiness','complete'];
        abort_unless(in_array($phase,$allowed,true),422,'Unsupported deployment phase.');
        $merged=array_replace_recursive((array)$run->evidence,['phases'=>array_merge((array)data_get($run->evidence,'phases',[]),[[$phase=>['at'=>now()->toIso8601String(),'evidence'=>$evidence]]])]);
        $run->update(['phase'=>$phase,'evidence'=>$merged]);
        return $run->fresh();
    }

    /** Handles complete for the deployment service workflow. */
    public function complete(DeploymentRun $run, array $evidence = []): DeploymentRun
    {
        abort_if($run->status !== 'running',409,'Only a running deployment can complete.');
        $run->update(['status'=>'completed','phase'=>'complete','migration_batch_after'=>$this->migrationBatch(),'completed_at'=>now(),'evidence'=>array_replace_recursive((array)$run->evidence,['completion'=>$evidence])]);
        return $run->fresh();
    }

    /** Handles fail for the deployment service workflow. */
    public function fail(DeploymentRun $run, string $reason, array $evidence = []): DeploymentRun
    {
        abort_if($run->status !== 'running',409,'Only a running deployment can fail.');
        $run->update(['status'=>'failed','failure_reason'=>mb_substr($reason,0,5000),'migration_batch_after'=>$this->migrationBatch(),'completed_at'=>now(),'evidence'=>array_replace_recursive((array)$run->evidence,['failure'=>$evidence])]);
        return $run->fresh();
    }

    /** Handles rolled back for the deployment service workflow. */
    public function rolledBack(DeploymentRun $run, string $targetRelease, array $evidence = []): DeploymentRun
    {
        abort_unless(in_array($run->status,['completed','failed'],true),409,'Deployment must be terminal before rollback evidence is recorded.');
        $run->update(['status'=>'rolled_back','completed_at'=>$run->completed_at ?: now(),'evidence'=>array_replace_recursive((array)$run->evidence,['rollback'=>['targetRelease'=>$targetRelease,'at'=>now()->toIso8601String(),'evidence'=>$evidence]])]);
        return $run->fresh();
    }

    /** Handles attach backup for the deployment service workflow. */
    public function attachBackup(DeploymentRun $run, BackupRun $backup): DeploymentRun
    {
        abort_unless($backup->status === 'completed' && $backup->verified_at,422,'Deployment backup must be completed and verified.');
        $run->update(['backup_run_id'=>$backup->id]);
        return $run->fresh();
    }

    /** Handles migration batch for the deployment service workflow. */
    private function migrationBatch(): ?int
    {
        try { return Schema::hasTable('migrations') ? (int) DB::table('migrations')->max('batch') : null; }
        catch (\Throwable) { return null; }
    }
}
