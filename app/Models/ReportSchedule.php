<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Defines the ReportSchedule class and its project responsibilities. */
class ReportSchedule extends Model
{
    protected $fillable=['public_id','user_id','name','report_type','cadence','timezone','run_at_local','weekday','day_of_month','filters','enabled','next_run_at','last_run_at'];
    /** Handles casts for the report schedule workflow. */
    protected function casts():array{return ['filters'=>'array','enabled'=>'boolean','weekday'=>'integer','day_of_month'=>'integer','next_run_at'=>'datetime','last_run_at'=>'datetime'];}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles user for the report schedule workflow. */
    public function user():BelongsTo{return $this->belongsTo(User::class);}
    /** Handles exports for the report schedule workflow. */
    public function exports():HasMany{return $this->hasMany(ReportExport::class);}
}
