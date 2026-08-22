<?php
namespace App\Jobs;

use App\Domain\Operations\Services\HeartbeatService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Defines the QueueHeartbeatJob class and its project responsibilities. */
class QueueHeartbeatJob implements ShouldQueue
{
    use Queueable;
    public int $tries=3;
    public int $timeout=20;
    /** Initializes the QueueHeartbeatJob instance and its dependencies. */
    public function __construct(){ $this->onQueue('critical'); }
    /** Executes the queue heartbeat job operation. */
    public function handle(HeartbeatService $heartbeats):void{$heartbeats->beat('queue-worker',['queue'=>'critical','release'=>(string)config('vsn.operations.release','unknown')]);}
    /** Handles backoff for the queue heartbeat job workflow. */
    public function backoff():array{return [5,15,30];}
}
