<?php
namespace App\Jobs;
use App\Domain\Catalog\Actions\EvaluateProductAlerts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
/** Defines the ReconcileProductAlertsJob class and its project responsibilities. */
class ReconcileProductAlertsJob implements ShouldQueue{use Queueable;public int $tries=5;public int $timeout=120;public function __construct(){ $this->onQueue('default'); }public function middleware():array{return [(new WithoutOverlapping('vsn:product-alerts'))->expireAfter(180)];}public function handle(EvaluateProductAlerts $a):void{$a->execute(null,(int)config('vsn.catalog.alert_scan_limit',1000));}public function backoff():array{return [10,30,60,120];}}
