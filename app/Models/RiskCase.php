<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
/** Defines the RiskCase class and its project responsibilities. */
class RiskCase extends Model {
    protected $fillable=['public_id','user_id','vendor_id','assigned_to_user_id','status','priority','title','summary','score_at_open','resolution','metadata','opened_at','closed_at'];
    /** Handles casts for the risk case workflow. */
    protected function casts():array{return ['score_at_open'=>'integer','metadata'=>'array','opened_at'=>'datetime','closed_at'=>'datetime'];}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles user for the risk case workflow. */
    public function user():BelongsTo{return $this->belongsTo(User::class);}
    /** Handles vendor for the risk case workflow. */
    public function vendor():BelongsTo{return $this->belongsTo(Vendor::class);}
    /** Handles assignee for the risk case workflow. */
    public function assignee():BelongsTo{return $this->belongsTo(User::class,'assigned_to_user_id');}
    /** Handles holds for the risk case workflow. */
    public function holds():HasMany{return $this->hasMany(RiskHold::class);}
}
