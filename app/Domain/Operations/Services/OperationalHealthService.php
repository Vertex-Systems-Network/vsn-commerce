<?php
namespace App\Domain\Operations\Services;

use App\Models\OperationalHeartbeat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Defines the OperationalHealthService class and its project responsibilities. */
class OperationalHealthService
{
    /** Handles snapshot for the operational health service workflow. */
    public function snapshot(bool $detailed=false): array
    {
        $checks=[];
        $checks['database']=$this->measure(/** Inline callback for this operation. */ function(){ DB::select('select 1'); return true; });
        $checks['cache']=$this->measure(/** Inline callback for this operation. */ function(){ $k='health:'.Str::random(12); Cache::put($k,'ok',10); $ok=Cache::get($k)==='ok'; Cache::forget($k); if(!$ok) throw new \RuntimeException('Cache round-trip failed.'); return true; });
        $checks['redis']=$this->measure(/** Inline callback for this operation. */ function(){ $pong=Redis::connection()->ping(); if($pong===false) throw new \RuntimeException('Redis ping failed.'); return true; });
        $checks['storage']=$this->measure(/** Inline callback for this operation. */ function(){ $disk=(string)config('vsn.operations.health_disk','local'); $p='health/'.Str::uuid().'.probe'; Storage::disk($disk)->put($p,'ok'); $ok=Storage::disk($disk)->exists($p); Storage::disk($disk)->delete($p); if(!$ok) throw new \RuntimeException('Storage probe failed.'); return true; });
        $checks['migrations']=$this->migrationCheck();
        $checks['scheduler']=$this->heartbeatCheck('scheduler',(int)config('vsn.operations.scheduler_stale_seconds',180),!config('vsn.operations.require_scheduler',false));
        $checks['queue_worker']=$this->heartbeatCheck('queue-worker',(int)config('vsn.operations.queue_worker_stale_seconds',180),!config('vsn.operations.require_queue_worker',false));
        $checks['queue_pressure']=$this->queuePressureCheck();

        $required=['database','cache','redis','storage','migrations'];
        if(config('vsn.operations.require_scheduler',false)) $required[]='scheduler';
        if(config('vsn.operations.require_queue_worker',false)) $required[]='queue_worker';
        if(config('vsn.operations.require_queue_pressure',false)) $required[]='queue_pressure';
        $ready=collect($required)->every(/** Inline callback for this operation. */ fn($key)=>($checks[$key]['ok']??false)===true);

        $payload=['status'=>$ready?'ready':'not_ready','checkedAt'=>now()->toIso8601String(),'checks'=>[]];
        foreach($checks as $name=>$check){
            $payload['checks'][$name]=$detailed?$check:['ok'=>(bool)($check['ok']??false),'latencyMs'=>$check['latencyMs']??null];
        }
        if($detailed){
            $payload['queues']=$this->queueDepths();
            $payload['failedJobs']=$this->failedJobsCount();
            $payload['app']=['environment'=>app()->environment(),'debug'=>(bool)config('app.debug'),'version'=>(string)config('vsn.operations.release','unknown')];
        }
        return $payload;
    }

    /** Handles measure for the operational health service workflow. */
    private function measure(callable $callback): array
    {
        $start=hrtime(true);
        try{$callback();return ['ok'=>true,'latencyMs'=>round((hrtime(true)-$start)/1_000_000,2)];}
        catch(\Throwable $e){return ['ok'=>false,'latencyMs'=>round((hrtime(true)-$start)/1_000_000,2),'error'=>class_basename($e)];}
    }
    /** Handles heartbeat check for the operational health service workflow. */
    private function heartbeatCheck(string $name,int $staleSeconds,bool $optional):array
    {
        try{
            $row=OperationalHeartbeat::query()->where('name',$name)->first();
            $age=$row?->last_seen_at?->diffInSeconds(now());
            $fresh=$row&&$age<=$staleSeconds;
            $expected=(string)config('vsn.operations.release','unknown');
            $reported=(string)data_get($row?->metadata,'release','');
            $releaseMatches=$optional || $expected==='unknown' || ($reported!=='' && hash_equals($expected,$reported));
            return ['ok'=>$optional||($fresh&&$releaseMatches),'optional'=>$optional,'lastSeenAt'=>$row?->last_seen_at?->toIso8601String(),'ageSeconds'=>$age,'staleAfterSeconds'=>$staleSeconds,'release'=>$reported?:null,'expectedRelease'=>$expected,'releaseMatches'=>$releaseMatches];
        }
        catch(\Throwable $e){return ['ok'=>$optional,'optional'=>$optional,'error'=>class_basename($e)];}
    }
    /** Handles migration check for the operational health service workflow. */
    private function migrationCheck():array
    {
        try{
            if(!Schema::hasTable('migrations')) return ['ok'=>false,'pending'=>null,'error'=>'migration_repository_missing'];
            $files=collect(File::files(database_path('migrations')))->map(/** Inline callback for this operation. */ fn($f)=>pathinfo($f->getFilename(),PATHINFO_FILENAME))->values();
            $ran=collect(DB::table('migrations')->pluck('migration'));
            $pending=$files->diff($ran)->values();
            return ['ok'=>$pending->isEmpty(),'pending'=>$pending->count(),'pendingNames'=>$pending->take(10)->all()];
        }catch(\Throwable $e){return ['ok'=>false,'pending'=>null,'error'=>class_basename($e)];}
    }
    /** Handles queue depths for the operational health service workflow. */
    private function queueDepths():array
    {
        $result=[];
        foreach((array)config('vsn.operations.monitored_queues',['critical','default','notifications','reports']) as $q){try{$result[$q]=(int)Redis::connection()->llen('queues:'.$q);}catch(\Throwable){$result[$q]=null;}}
        return $result;
    }
    /** Handles queue pressure check for the operational health service workflow. */
    private function queuePressureCheck():array
    {
        try{
            $depths=$this->queueDepths();
            $max=(int)config('vsn.operations.queue_busy_max',500);
            $known=array_filter($depths,/** Inline callback for this operation. */ fn($v)=>$v!==null);
            $peak=$known?max($known):null;
            return ['ok'=>$peak!==null && $peak<=$max,'maxDepth'=>$peak,'threshold'=>$max,'queues'=>$depths];
        }catch(\Throwable $e){return ['ok'=>false,'error'=>class_basename($e)];}
    }
    /** Handles failed jobs count for the operational health service workflow. */
    private function failedJobsCount():?int
    { try{return Schema::hasTable('failed_jobs')?(int)DB::table('failed_jobs')->count():null;}catch(\Throwable){return null;} }
}
