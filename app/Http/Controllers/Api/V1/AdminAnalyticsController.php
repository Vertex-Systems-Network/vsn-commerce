<?php
namespace App\Http\Controllers\Api\V1;

use App\Domain\Reporting\Actions\QueueReportExport;
use App\Domain\Reporting\Services\MarketplaceAnalyticsService;
use App\Domain\Reporting\Services\ReportDatasetBuilder;
use App\Domain\Reporting\Services\ReportFilterService;
use App\Domain\Reporting\Services\ReportScheduleService;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ReportExport;
use App\Models\ReportSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Defines the AdminAnalyticsController class and its project responsibilities. */
class AdminAnalyticsController extends Controller
{
    /** Handles dashboard for the admin analytics controller workflow. */
    public function dashboard(Request $request,MarketplaceAnalyticsService $analytics,ReportDatasetBuilder $datasets):JsonResponse
    {
        $this->authorizeReporting($request);$filters=$this->filters($request);
        return response()->json(['data'=>['analytics'=>$analytics->dashboard($filters),'reportTypes'=>$datasets->types(),'exports'=>$this->exportRows(),'schedules'=>$this->scheduleRows()]]);
    }

    /** Handles exports for the admin analytics controller workflow. */
    public function exports(Request $request):JsonResponse{$this->authorizeReporting($request);return response()->json(['data'=>$this->exportRows()]);}

    /** Handles create export for the admin analytics controller workflow. */
    public function createExport(Request $request,QueueReportExport $queue,ReportDatasetBuilder $datasets,ReportFilterService $filterService):JsonResponse
    {
        $this->authorizeReporting($request);$d=$request->validate(['reportType'=>'required|string|in:'.implode(',',$datasets->types()),'format'=>'nullable|in:csv','filters'=>'nullable|array','filters.from'=>'nullable|date','filters.to'=>'nullable|date','filters.timezone'=>'nullable|timezone','filters.currency'=>'nullable|string|size:3','filters.period'=>'nullable|in:last_7_days,last_30_days,month_to_date,previous_month']);
        $export=$queue->execute($request->user(),$d['reportType'],$filterService->resolve($d['filters']??[]));
        return response()->json(['data'=>$this->exportRow($export)],202);
    }

    /** Handles download for the admin analytics controller workflow. */
    public function download(Request $request,ReportExport $export)
    {
        $this->authorizeReporting($request);$this->authorizeRecord($request,$export->requested_by_user_id);abort_unless($export->status==='ready'&&$export->storage_disk&&$export->storage_path,409,'Report is not ready.');abort_if($export->expires_at?->isPast(),410,'Report export expired.');$disk=Storage::disk($export->storage_disk);abort_unless($disk->exists($export->storage_path),404,'Report file is unavailable.');$name=Str::slug($export->report_type).'-'.($export->ready_at?->format('Ymd-His')??now()->format('Ymd-His')).'.csv';return $disk->download($export->storage_path,$name,['Content-Type'=>$export->mime_type??'text/csv; charset=UTF-8','X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, no-store']);
    }

    /** Handles schedules for the admin analytics controller workflow. */
    public function schedules(Request $request):JsonResponse{$this->authorizeReporting($request);return response()->json(['data'=>$this->scheduleRows()]);}

    /** Handles create schedule for the admin analytics controller workflow. */
    public function createSchedule(Request $request,ReportDatasetBuilder $datasets,ReportScheduleService $clock):JsonResponse
    {
        $this->authorizeReporting($request);$d=$this->scheduleValidation($request,$datasets);$enabled=(bool)($d['enabled']??true);$schedule=ReportSchedule::query()->create(['public_id'=>(string)Str::ulid(),'user_id'=>$request->user()->id,...$d,'next_run_at'=>$enabled?$clock->next($d):null]);return response()->json(['data'=>$this->scheduleRow($schedule)],201);
    }

    /** Handles update schedule for the admin analytics controller workflow. */
    public function updateSchedule(Request $request,ReportSchedule $schedule,ReportDatasetBuilder $datasets,ReportScheduleService $clock):JsonResponse
    {
        $this->authorizeReporting($request);$this->authorizeRecord($request,$schedule->user_id);$d=$this->scheduleValidation($request,$datasets,true);$schedule->fill($d);$schedule->next_run_at=$schedule->enabled?$clock->next($schedule):null;$schedule->save();return response()->json(['data'=>$this->scheduleRow($schedule)]);
    }

    /** Handles delete schedule for the admin analytics controller workflow. */
    public function deleteSchedule(Request $request,ReportSchedule $schedule):JsonResponse{$this->authorizeReporting($request);$this->authorizeRecord($request,$schedule->user_id);$schedule->delete();return response()->json(['data'=>['deleted'=>true]]);}

    /** Handles filters for the admin analytics controller workflow. */
    private function filters(Request $request):array{return $request->validate(['from'=>'nullable|date','to'=>'nullable|date','timezone'=>'nullable|timezone','currency'=>'nullable|string|size:3']);}
    /** Handles schedule validation for the admin analytics controller workflow. */
    private function scheduleValidation(Request $request,ReportDatasetBuilder $datasets,bool $partial=false):array
    {
        $p=$partial?'sometimes':'required';return $request->validate(['name'=>"{$p}|string|max:160",'report_type'=>"{$p}|string|in:".implode(',',$datasets->types()),'cadence'=>"{$p}|string|in:daily,weekly,monthly",'timezone'=>"{$p}|timezone",'run_at_local'=>[$p,'regex:/^(?:[01]\d|2[0-3]):[0-5]\d$/'],'weekday'=>'nullable|integer|min:1|max:7','day_of_month'=>'nullable|integer|min:1|max:28','filters'=>'nullable|array','filters.from'=>'nullable|date','filters.to'=>'nullable|date','filters.timezone'=>'nullable|timezone','filters.currency'=>'nullable|string|size:3','filters.period'=>'nullable|in:last_7_days,last_30_days,month_to_date,previous_month','enabled'=>'sometimes|boolean']);
    }
    /** Handles authorize reporting for the admin analytics controller workflow. */
    private function authorizeReporting(Request $request):void{$v=$this->roleValue($request->user()?->role);abort_unless(in_array($v,[UserRole::Finance->value,UserRole::Admin->value,UserRole::SuperAdmin->value],true),403);}
    /** Handles authorize record for the admin analytics controller workflow. */
    private function authorizeRecord(Request $request,int $ownerId):void{$v=$this->roleValue($request->user()?->role);if($v===UserRole::Finance->value)abort_unless((int)$request->user()->id===$ownerId,403);}
    /** Handles role value for the admin analytics controller workflow. */
    private function roleValue(mixed $role):string{return $role instanceof UserRole?$role->value:(string)$role;}
    /** Handles export rows for the admin analytics controller workflow. */
    private function exportRows():array{$q=ReportExport::query()->with(['requester:id,name','schedule:id,public_id,name']);$user=request()->user();if($this->roleValue($user?->role)===UserRole::Finance->value)$q->where('requested_by_user_id',$user->id);return $q->latest()->limit(100)->get()->map(/** Inline callback for this operation. */ fn($e)=>$this->exportRow($e))->all();}
    /** Handles export row for the admin analytics controller workflow. */
    private function exportRow(ReportExport $e):array{return ['id'=>$e->public_id,'reportType'=>$e->report_type,'format'=>$e->format,'status'=>$e->status,'filters'=>$e->filters??[],'rowsCount'=>(int)$e->rows_count,'sizeBytes'=>$e->size_bytes,'sha256'=>$e->sha256,'requestedBy'=>$e->requester?->name,'schedule'=>$e->schedule?->name,'createdAt'=>$e->created_at?->toIso8601String(),'readyAt'=>$e->ready_at?->toIso8601String(),'expiresAt'=>$e->expires_at?->toIso8601String(),'error'=>$e->error_message,'downloadUrl'=>$e->status==='ready'&&!$e->expires_at?->isPast()?"/api/v1/admin/analytics/exports/{$e->public_id}/download":null];}
    /** Handles schedule rows for the admin analytics controller workflow. */
    private function scheduleRows():array{$q=ReportSchedule::query()->with('user:id,name');$user=request()->user();if($this->roleValue($user?->role)===UserRole::Finance->value)$q->where('user_id',$user->id);return $q->latest()->limit(100)->get()->map(/** Inline callback for this operation. */ fn($s)=>$this->scheduleRow($s))->all();}
    /** Handles schedule row for the admin analytics controller workflow. */
    private function scheduleRow(ReportSchedule $s):array{return ['id'=>$s->public_id,'name'=>$s->name,'reportType'=>$s->report_type,'cadence'=>$s->cadence,'timezone'=>$s->timezone,'runAtLocal'=>$s->run_at_local,'weekday'=>$s->weekday,'dayOfMonth'=>$s->day_of_month,'filters'=>$s->filters??[],'enabled'=>(bool)$s->enabled,'owner'=>$s->user?->name,'nextRunAt'=>$s->next_run_at?->toIso8601String(),'lastRunAt'=>$s->last_run_at?->toIso8601String()];}
}
