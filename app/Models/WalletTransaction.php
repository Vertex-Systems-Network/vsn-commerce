<?php
namespace App\Models;

use App\Enums\WalletTransactionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Defines the WalletTransaction class and its project responsibilities. */
class WalletTransaction extends Model
{
    use HasFactory;
    protected $fillable = ['public_id','initiated_by_user_id','type','status','idempotency_key','reference_type','reference_id','reversal_of_transaction_id','metadata','occurred_at'];
    /** Handles casts for the wallet transaction workflow. */
    protected function casts(): array { return ['type'=>WalletTransactionType::class,'metadata'=>'array','occurred_at'=>'datetime']; }
    /** Handles booted for the wallet transaction workflow. */
    protected static function booted(): void
    {
        static::updating(/** Inline callback for this operation. */ fn () => throw new \LogicException('Wallet ledger transactions are immutable; create a reversal instead.'));
        static::deleting(/** Inline callback for this operation. */ fn () => throw new \LogicException('Wallet ledger transactions are immutable.'));
    }
    /** Returns route key name. */
    public function getRouteKeyName(): string { return 'public_id'; }
    /** Handles initiator for the wallet transaction workflow. */
    public function initiator(): BelongsTo { return $this->belongsTo(User::class, 'initiated_by_user_id'); }
    /** Handles entries for the wallet transaction workflow. */
    public function entries(): HasMany { return $this->hasMany(WalletEntry::class); }
    /** Handles reversal of for the wallet transaction workflow. */
    public function reversalOf(): BelongsTo { return $this->belongsTo(self::class, 'reversal_of_transaction_id'); }
}
