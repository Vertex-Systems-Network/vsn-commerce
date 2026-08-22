<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the DisputeMessage class and its project responsibilities. */
class DisputeMessage extends Model
{
    protected $fillable=['dispute_id','user_id','message','attachments'];
    /** Handles casts for the dispute message workflow. */
    protected function casts(): array { return ['attachments'=>'array']; }
    /** Handles dispute for the dispute message workflow. */
    public function dispute(): BelongsTo { return $this->belongsTo(Dispute::class); }
    /** Handles user for the dispute message workflow. */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
