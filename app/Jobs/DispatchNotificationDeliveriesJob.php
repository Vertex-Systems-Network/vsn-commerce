<?php
namespace App\Jobs;
use App\Domain\Notifications\Actions\DispatchNotificationDeliveries;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
/** Defines the DispatchNotificationDeliveriesJob class and its project responsibilities. */
class DispatchNotificationDeliveriesJob implements ShouldQueue{use Queueable;public int $tries=5;public int $timeout=120;public function __construct(){ $this->onQueue('notifications'); }public function middleware():array{return [(new WithoutOverlapping('vsn:notifications-dispatch'))->expireAfter(180)];}public function handle(DispatchNotificationDeliveries $a):void{$a->execute((int)config('vsn.notifications.delivery_batch_size',200));}public function backoff():array{return [10,30,60,180];}}
