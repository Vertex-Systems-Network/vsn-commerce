<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the ReviewReport class and its project responsibilities. */
class ReviewReport extends Model
{
    protected $fillable=['public_id','review_id','user_id','reason','details','status','resolution_note','resolved_by','resolved_at'];
    /** Handles casts for the review report workflow. */
    protected function casts():array{return ['resolved_at'=>'datetime'];}
    /** Returns route key name. */
    public function getRouteKeyName():string{return 'public_id';}
    /** Handles review for the review report workflow. */
    public function review():BelongsTo{return $this->belongsTo(Review::class);}
    /** Handles user for the review report workflow. */
    public function user():BelongsTo{return $this->belongsTo(User::class);}
    /** Handles resolver for the review report workflow. */
    public function resolver():BelongsTo{return $this->belongsTo(User::class,'resolved_by');}
}
