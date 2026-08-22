<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Defines the Wallet class and its project responsibilities. */
class Wallet extends Model
{
    use HasFactory;
    protected $fillable = ['user_id','balance_coins','reserved_coins'];
    /** Handles casts for the wallet workflow. */
    protected function casts(): array { return ['balance_coins'=>'integer','reserved_coins'=>'integer']; }
    /** Handles user for the wallet workflow. */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    /** Handles entries for the wallet workflow. */
    public function entries(): HasMany { return $this->hasMany(WalletEntry::class); }
    /** Handles holds for the wallet workflow. */
    public function holds(): HasMany { return $this->hasMany(WalletHold::class); }
    /** Handles available coins for the wallet workflow. */
    public function availableCoins(): int { return max(0, $this->balance_coins - $this->reserved_coins); }
}
