<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the GameEntryRefund class and its project responsibilities. */
class GameEntryRefund extends Model
{
    use HasFactory;
    protected $fillable = ['game_entry_id','wallet_transaction_id','reason','refunded_at'];
    /** Handles casts for the game entry refund workflow. */
    protected function casts(): array { return ['refunded_at'=>'datetime']; }
    /** Handles booted for the game entry refund workflow. */
    protected static function booted(): void
    {
        static::updating(/** Inline callback for this operation. */ fn () => throw new \LogicException('Game refund records are immutable.'));
        static::deleting(/** Inline callback for this operation. */ fn () => throw new \LogicException('Game refund records are immutable.'));
    }
    /** Handles entry for the game entry refund workflow. */
    public function entry(): BelongsTo { return $this->belongsTo(GameEntry::class, 'game_entry_id'); }
    /** Handles wallet transaction for the game entry refund workflow. */
    public function walletTransaction(): BelongsTo { return $this->belongsTo(WalletTransaction::class); }
}
