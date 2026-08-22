<?php
namespace App\Models;

use App\Enums\GameStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Defines the Game class and its project responsibilities. */
class Game extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id','product_id','status','entry_coins','winner_bonus_coins','max_entries','max_entries_per_user','total_entries','opens_at','closes_at','announcement_at',
        'rules_version','commitment_hash','draw_secret_ciphertext','closed_at','drawn_at','fulfilled_at','cancelled_at',
        'cancellation_reason','metadata',
    ];
    protected $hidden = ['draw_secret_ciphertext'];
    /** Handles casts for the game workflow. */
    protected function casts(): array
    {
        return [
            'status'=>GameStatus::class,'entry_coins'=>'integer','winner_bonus_coins'=>'integer','max_entries'=>'integer','max_entries_per_user'=>'integer','total_entries'=>'integer',
            'opens_at'=>'datetime','closes_at'=>'datetime','announcement_at'=>'datetime','closed_at'=>'datetime','drawn_at'=>'datetime',
            'fulfilled_at'=>'datetime','cancelled_at'=>'datetime','metadata'=>'array',
        ];
    }
    /** Returns route key name. */
    public function getRouteKeyName(): string { return 'public_id'; }
    /** Handles product for the game workflow. */
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    /** Handles entries for the game workflow. */
    public function entries(): HasMany { return $this->hasMany(GameEntry::class); }
    /** Handles draw for the game workflow. */
    public function draw(): HasOne { return $this->hasOne(GameDraw::class); }
    /** Handles fulfillment for the game workflow. */
    public function fulfillment(): HasOne { return $this->hasOne(GamePrizeFulfillment::class); }
}
