<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the RefundEvent class and its project responsibilities. */
class RefundEvent extends Model
{
    protected $fillable=['refund_id','actor_user_id','event','reference','message','metadata','occurred_at'];
    /** Handles casts for the refund event workflow. */
    protected function casts():array{return ['metadata'=>'array','occurred_at'=>'datetime'];}
    /** Handles refund for the refund event workflow. */
    public function refund():BelongsTo{return $this->belongsTo(Refund::class);}
    /** Handles actor for the refund event workflow. */
    public function actor():BelongsTo{return $this->belongsTo(User::class,'actor_user_id');}
}
