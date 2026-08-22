<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the RiskHold class and its project responsibilities. */
class RiskHold extends Model {
    protected $fillable=['public_id','user_id','vendor_id','risk_case_id','created_by_user_id','released_by_user_id','scope','status','reason','starts_at','expires_at','released_at','release_note','metadata'];
    /** Handles casts for the risk hold workflow. */
    protected function casts():array{return ['starts_at'=>'datetime','expires_at'=>'datetime','released_at'=>'datetime','metadata'=>'array'];}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles user for the risk hold workflow. */
    public function user():BelongsTo{return $this->belongsTo(User::class);}
    /** Handles vendor for the risk hold workflow. */
    public function vendor():BelongsTo{return $this->belongsTo(Vendor::class);}
    /** Handles risk case for the risk hold workflow. */
    public function riskCase():BelongsTo{return $this->belongsTo(RiskCase::class);}
    /** Handles creator for the risk hold workflow. */
    public function creator():BelongsTo{return $this->belongsTo(User::class,'created_by_user_id');}
    /** Handles releaser for the risk hold workflow. */
    public function releaser():BelongsTo{return $this->belongsTo(User::class,'released_by_user_id');}
    /** Handles active for the risk hold workflow. */
    public function active():bool{return $this->status==='active'&&$this->starts_at?->lte(now())&&(!$this->expires_at||$this->expires_at->isFuture());}
}
