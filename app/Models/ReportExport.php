<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the ReportExport class and its project responsibilities. */
class ReportExport extends Model
{
    protected $fillable=['public_id','requested_by_user_id','report_schedule_id','report_type','format','status','filters','storage_disk','storage_path','mime_type','sha256','size_bytes','rows_count','started_at','ready_at','expires_at','error_message','metadata'];
    /** Handles casts for the report export workflow. */
    protected function casts():array{return ['filters'=>'array','metadata'=>'array','size_bytes'=>'integer','rows_count'=>'integer','started_at'=>'datetime','ready_at'=>'datetime','expires_at'=>'datetime'];}
    protected $hidden=['storage_path'];
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles requester for the report export workflow. */
    public function requester():BelongsTo{return $this->belongsTo(User::class,'requested_by_user_id');}
    /** Handles schedule for the report export workflow. */
    public function schedule():BelongsTo{return $this->belongsTo(ReportSchedule::class,'report_schedule_id');}
}
