<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the AffiliateAccountEvent class and its project responsibilities. */
class AffiliateAccountEvent extends Model
{
    protected $fillable = ['affiliate_account_id','actor_user_id','event_type','from_status','to_status','reason','metadata','occurred_at'];
    /** Handles casts for the affiliate account event workflow. */
    protected function casts(): array { return ['metadata'=>'array','occurred_at'=>'datetime']; }
    /** Handles booted for the affiliate account event workflow. */
    protected static function booted(): void {
        static::updating(/** Inline callback for this operation. */ fn () => throw new \LogicException('Affiliate account events are immutable.'));
        static::deleting(/** Inline callback for this operation. */ fn () => throw new \LogicException('Affiliate account events are immutable.'));
    }
    /** Handles account for the affiliate account event workflow. */
    public function account(): BelongsTo { return $this->belongsTo(AffiliateAccount::class, 'affiliate_account_id'); }
    /** Handles actor for the affiliate account event workflow. */
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
}
