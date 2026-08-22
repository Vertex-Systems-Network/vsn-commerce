<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the RiskProfile class and its project responsibilities. */
class RiskProfile extends Model {
    protected $fillable=['public_id','user_id','vendor_id','score','level','status','signal_summary','last_evaluated_at'];
    /** Handles casts for the risk profile workflow. */
    protected function casts():array{return ['score'=>'integer','signal_summary'=>'array','last_evaluated_at'=>'datetime'];}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles user for the risk profile workflow. */
    public function user():BelongsTo{return $this->belongsTo(User::class);}
    /** Handles vendor for the risk profile workflow. */
    public function vendor():BelongsTo{return $this->belongsTo(Vendor::class);}
}
