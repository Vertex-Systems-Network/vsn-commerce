<?php
namespace App\Domain\Reporting\Actions;

use App\Domain\Reporting\Services\ReportDatasetBuilder;
use App\Models\ReportExport;
use App\Models\ReportSchedule;
use App\Models\User;
use Illuminate\Support\Str;

/** Defines the QueueReportExport class and its project responsibilities. */
class QueueReportExport
{
    /** Initializes the QueueReportExport instance and its dependencies. */
    public function __construct(private readonly ReportDatasetBuilder $builder){}
    /** Executes the queue report export operation. */
    public function execute(User $user,string $type,array $filters=[],?ReportSchedule $schedule=null):ReportExport
    {
        abort_unless(in_array($type,$this->builder->types(),true),422,'Unsupported report type.');
        return ReportExport::query()->create(['public_id'=>(string)Str::ulid(),'requested_by_user_id'=>$user->id,'report_schedule_id'=>$schedule?->id,'report_type'=>$type,'format'=>'csv','status'=>'queued','filters'=>$filters,'expires_at'=>now()->addDays((int)config('vsn.reporting.export_retention_days',14))]);
    }
}
