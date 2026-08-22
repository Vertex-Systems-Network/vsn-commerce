<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Defines the GameEntry class and its project responsibilities. */
class GameEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id','game_id','user_id','quantity','coins_spent','idempotency_key','wallet_transaction_id',
        'rules_version','consented_at','ip_hash','user_agent_hash',
    ];
    /** Handles casts for the game entry workflow. */
    protected function casts(): array { return ['quantity'=>'integer','coins_spent'=>'integer','consented_at'=>'datetime']; }
    /** Handles booted for the game entry workflow. */
    protected static function booted(): void
    {
        static::updating(/** Inline callback for this operation. */ fn () => throw new \LogicException('Game entry facts are immutable; create a refund record instead.'));
        static::deleting(/** Inline callback for this operation. */ fn () => throw new \LogicException('Game entries are immutable.'));
    }
    /** Returns route key name. */
    public function getRouteKeyName(): string { return 'public_id'; }
    /** Handles game for the game entry workflow. */
    public function game(): BelongsTo { return $this->belongsTo(Game::class); }
    /** Handles user for the game entry workflow. */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    /** Handles wallet transaction for the game entry workflow. */
    public function walletTransaction(): BelongsTo { return $this->belongsTo(WalletTransaction::class); }
    /** Handles refund for the game entry workflow. */
    public function refund(): HasOne { return $this->hasOne(GameEntryRefund::class); }
}
