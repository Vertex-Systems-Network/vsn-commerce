<?php
namespace App\Jobs;
use App\Domain\Operations\Services\DatabaseBackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
/** Defines the CreateDatabaseBackupJob class and its project responsibilities. */
class CreateDatabaseBackupJob implements ShouldQueue{use Queueable;public int $tries=2;public int $timeout=900;public function __construct(){ $this->onQueue('maintenance'); }public function middleware():array{return [(new WithoutOverlapping('vsn:database-backup'))->expireAfter(1200)];}public function handle(DatabaseBackupService $s):void{$s->create();}public function backoff():array{return [60,300];}}
