<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the GiftNotification class and its project responsibilities. */
class GiftNotification extends Model
{
    use HasFactory;
    protected $fillable = ['public_id','gift_id','recipient_user_id','type','status','available_at','delivered_at','payload'];
    /** Handles casts for the gift notification workflow. */
    protected function casts(): array { return ['available_at'=>'datetime','delivered_at'=>'datetime','payload'=>'array']; }
    /** Handles gift for the gift notification workflow. */
    public function gift(): BelongsTo { return $this->belongsTo(Gift::class); }
    /** Handles recipient for the gift notification workflow. */
    public function recipient(): BelongsTo { return $this->belongsTo(User::class, 'recipient_user_id'); }
}
