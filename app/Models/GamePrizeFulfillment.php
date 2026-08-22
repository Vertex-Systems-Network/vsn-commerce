<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the GamePrizeFulfillment class and its project responsibilities. */
class GamePrizeFulfillment extends Model
{
    use HasFactory;
    protected $fillable = ['game_id','winner_user_id','fulfilled_by_user_id','wallet_transaction_id','method','reference','note','fulfilled_at'];
    /** Handles casts for the game prize fulfillment workflow. */
    protected function casts(): array { return ['fulfilled_at'=>'datetime']; }
    /** Handles booted for the game prize fulfillment workflow. */
    protected static function booted(): void
    {
        static::updating(/** Inline callback for this operation. */ fn () => throw new \LogicException('Game prize fulfillment audit records are immutable.'));
        static::deleting(/** Inline callback for this operation. */ fn () => throw new \LogicException('Game prize fulfillment audit records are immutable.'));
    }
    /** Handles game for the game prize fulfillment workflow. */
    public function game(): BelongsTo { return $this->belongsTo(Game::class); }
    /** Handles winner for the game prize fulfillment workflow. */
    public function winner(): BelongsTo { return $this->belongsTo(User::class, 'winner_user_id'); }
    /** Handles fulfilled by for the game prize fulfillment workflow. */
    public function fulfilledBy(): BelongsTo { return $this->belongsTo(User::class, 'fulfilled_by_user_id'); }
    /** Handles wallet transaction for the game prize fulfillment workflow. */
    public function walletTransaction(): BelongsTo { return $this->belongsTo(WalletTransaction::class); }
}
