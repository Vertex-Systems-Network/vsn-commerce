<?php
namespace App\Jobs;
use App\Domain\Reporting\Actions\ProcessQueuedReportExports;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
/** Defines the GenerateReportExportsJob class and its project responsibilities. */
class GenerateReportExportsJob implements ShouldQueue{use Queueable;public int $tries=4;public int $timeout=300;public function __construct(){ $this->onQueue('reports'); }public function middleware():array{return [(new WithoutOverlapping('vsn:reports-generate'))->expireAfter(360)];}public function handle(ProcessQueuedReportExports $a):void{$a->execute();}public function backoff():array{return [30,60,180];}}
