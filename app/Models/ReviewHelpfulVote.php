<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the ReviewHelpfulVote class and its project responsibilities. */
class ReviewHelpfulVote extends Model
{
    public $timestamps=false;
    protected $fillable=['review_id','user_id','created_at'];
    /** Handles casts for the review helpful vote workflow. */
    protected function casts():array{return ['created_at'=>'datetime'];}
    /** Handles review for the review helpful vote workflow. */
    public function review():BelongsTo{return $this->belongsTo(Review::class);}
    /** Handles user for the review helpful vote workflow. */
    public function user():BelongsTo{return $this->belongsTo(User::class);}
}
