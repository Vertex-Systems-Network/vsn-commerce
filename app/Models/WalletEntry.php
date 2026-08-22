<?php
namespace App\Models;

use App\Enums\WalletEntryDirection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the WalletEntry class and its project responsibilities. */
class WalletEntry extends Model
{
    use HasFactory;
    protected $fillable = ['wallet_transaction_id','wallet_id','user_id','direction','coins','balance_after_coins','metadata'];
    /** Handles casts for the wallet entry workflow. */
    protected function casts(): array { return ['direction'=>WalletEntryDirection::class,'coins'=>'integer','balance_after_coins'=>'integer','metadata'=>'array']; }
    /** Handles booted for the wallet entry workflow. */
    protected static function booted(): void
    {
        static::updating(/** Inline callback for this operation. */ fn () => throw new \LogicException('Wallet ledger entries are immutable.'));
        static::deleting(/** Inline callback for this operation. */ fn () => throw new \LogicException('Wallet ledger entries are immutable.'));
    }
    /** Handles transaction for the wallet entry workflow. */
    public function transaction(): BelongsTo { return $this->belongsTo(WalletTransaction::class, 'wallet_transaction_id'); }
    /** Handles wallet for the wallet entry workflow. */
    public function wallet(): BelongsTo { return $this->belongsTo(Wallet::class); }
    /** Handles user for the wallet entry workflow. */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
