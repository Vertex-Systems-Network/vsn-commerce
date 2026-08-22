<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the GameDraw class and its project responsibilities. */
class GameDraw extends Model
{
    use HasFactory;
    protected $fillable = [
        'public_id','game_id','commitment_hash','snapshot_hash','snapshot','snapshot_canonical','revealed_secret','selection_hash','total_tickets',
        'winning_ticket_number','winner_user_id','winner_entry_id','drawn_at',
    ];
    /** Handles casts for the game draw workflow. */
    protected function casts(): array { return ['snapshot'=>'array','total_tickets'=>'integer','winning_ticket_number'=>'integer','drawn_at'=>'datetime']; }
    /** Handles booted for the game draw workflow. */
    protected static function booted(): void
    {
        static::updating(/** Inline callback for this operation. */ fn () => throw new \LogicException('Game draw audit records are immutable.'));
        static::deleting(/** Inline callback for this operation. */ fn () => throw new \LogicException('Game draw audit records are immutable.'));
    }
    /** Returns route key name. */
    public function getRouteKeyName(): string { return 'public_id'; }
    /** Handles game for the game draw workflow. */
    public function game(): BelongsTo { return $this->belongsTo(Game::class); }
    /** Handles winner for the game draw workflow. */
    public function winner(): BelongsTo { return $this->belongsTo(User::class, 'winner_user_id'); }
    /** Handles winning entry for the game draw workflow. */
    public function winningEntry(): BelongsTo { return $this->belongsTo(GameEntry::class, 'winner_entry_id'); }
}
