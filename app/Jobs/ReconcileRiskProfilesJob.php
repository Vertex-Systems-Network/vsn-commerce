<?php
namespace App\Jobs;
use App\Domain\Risk\Actions\ReconcileRiskProfiles;
use App\Models\RiskHold;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
/** Defines the ReconcileRiskProfilesJob class and its project responsibilities. */
class ReconcileRiskProfilesJob implements ShouldQueue{use Queueable;public int $tries=5;public int $timeout=180;public function __construct(){ $this->onQueue('default'); }public function middleware():array{return [(new WithoutOverlapping('vsn:risk-reconcile'))->expireAfter(240)];}public function handle(ReconcileRiskProfiles $a):void{$a->execute((int)config('vsn.risk.reconcile_limit',500));RiskHold::query()->where('status','active')->whereNotNull('expires_at')->where('expires_at','<=',now())->update(['status'=>'expired']);}public function backoff():array{return [15,30,60,180];}}
