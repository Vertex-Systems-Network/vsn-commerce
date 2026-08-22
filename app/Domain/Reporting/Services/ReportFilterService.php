<?php
namespace App\Domain\Reporting\Services;

use Carbon\CarbonImmutable;
/** Defines the ReportFilterService class and its project responsibilities. */
class ReportFilterService
{
    public const PERIODS=['last_7_days','last_30_days','month_to_date','previous_month'];
    /** Handles resolve for the report filter service workflow. */
    public function resolve(array $filters):array
    {
        $period=$filters['period']??null;if(!$period)return $filters;
        abort_unless(in_array($period,self::PERIODS,true),422,'Unsupported rolling report period.');
        $tz=(string)($filters['timezone']??config('vsn.reporting.timezone','Asia/Karachi'));$now=CarbonImmutable::now($tz);
        [$from,$to]=match($period){
            'last_7_days'=>[$now->subDays(6)->startOfDay(),$now->endOfDay()],
            'last_30_days'=>[$now->subDays(29)->startOfDay(),$now->endOfDay()],
            'month_to_date'=>[$now->startOfMonth(),$now->endOfDay()],
            'previous_month'=>[$now->subMonthNoOverflow()->startOfMonth(),$now->subMonthNoOverflow()->endOfMonth()],
        };
        unset($filters['period']);$filters['from']=$from->toDateString();$filters['to']=$to->toDateString();$filters['timezone']=$tz;return $filters;
    }
}
