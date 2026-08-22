<?php
namespace App\Domain\Reporting\Services;

use App\Models\ReportSchedule;
use Carbon\CarbonImmutable;

/** Defines the ReportScheduleService class and its project responsibilities. */
class ReportScheduleService
{
    /** Handles next for the report schedule service workflow. */
    public function next(array|ReportSchedule $data,?CarbonImmutable $after=null):CarbonImmutable
    {
        $get=/** Inline callback for this operation. */ fn(string $k,$default=null)=>$data instanceof ReportSchedule?($data->{$k}??$default):($data[$k]??$default);
        $tz=(string)$get('timezone',config('vsn.reporting.timezone','Asia/Karachi'));
        $after=($after??CarbonImmutable::now('UTC'))->setTimezone($tz);
        [$h,$m]=array_map('intval',explode(':',(string)$get('run_at_local','08:00')));
        $candidate=$after->setTime($h,$m,0);
        $cadence=(string)$get('cadence','daily');
        if($cadence==='daily'){if($candidate->lte($after))$candidate=$candidate->addDay();return $candidate->utc();}
        if($cadence==='weekly'){$weekday=max(1,min(7,(int)$get('weekday',1)));$delta=($weekday-$candidate->isoWeekday()+7)%7;$candidate=$candidate->addDays($delta);if($candidate->lte($after))$candidate=$candidate->addWeek();return $candidate->utc();}
        $day=max(1,min(28,(int)$get('day_of_month',1)));$candidate=$candidate->day(min($day,$candidate->daysInMonth));if($candidate->lte($after)){$candidate=$candidate->addMonthNoOverflow()->day(min($day,$candidate->daysInMonth));}return $candidate->utc();
    }
}
