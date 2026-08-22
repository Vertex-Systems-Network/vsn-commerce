<?php
namespace App\Domain\Reporting\Actions;

use App\Domain\Reporting\Services\ReportScheduleService;
use App\Domain\Reporting\Services\ReportFilterService;
use App\Models\ReportSchedule;
use Illuminate\Support\Facades\DB;

/** Defines the RunScheduledReports class and its project responsibilities. */
class RunScheduledReports
{
    /** Initializes the RunScheduledReports instance and its dependencies. */
    public function __construct(private readonly QueueReportExport $queue,private readonly ReportScheduleService $clock,private readonly ReportFilterService $filters){}
    /** Executes the run scheduled reports operation. */
    public function execute(int $limit=100):int
    {
        $count=0;
        ReportSchedule::query()->where('enabled',true)->whereNotNull('next_run_at')->where('next_run_at','<=',now())->orderBy('next_run_at')->limit($limit)->get()->each(/** Inline callback for this operation. */ function(ReportSchedule $schedule)use(&$count):void{
            DB::transaction(/** Inline callback for this operation. */ function()use($schedule,&$count):void{
                $locked=ReportSchedule::query()->whereKey($schedule->id)->lockForUpdate()->first();if(!$locked||!$locked->enabled||!$locked->next_run_at||$locked->next_run_at->isFuture())return;
                $this->queue->execute($locked->user()->firstOrFail(),$locked->report_type,$this->filters->resolve($locked->filters??[]),$locked);
                $ranAt=now();$locked->update(['last_run_at'=>$ranAt,'next_run_at'=>$this->clock->next($locked,\Carbon\CarbonImmutable::instance($ranAt))]);$count++;
            },3);
        });
        return $count;
    }
}
