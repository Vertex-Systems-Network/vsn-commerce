<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Defines the WalletCoinLot class and its project responsibilities. */
class WalletCoinLot extends Model
{
    protected $fillable = ['wallet_id','user_id','source_transaction_id','origin_lot_id','source_type','original_coins','remaining_coins','expires_at','expired_at','expiration_transaction_id','metadata'];
    /** Handles casts for the wallet coin lot workflow. */
    protected function casts(): array { return ['original_coins'=>'integer','remaining_coins'=>'integer','expires_at'=>'datetime','expired_at'=>'datetime','metadata'=>'array']; }
    /** Handles wallet for the wallet coin lot workflow. */
    public function wallet(): BelongsTo { return $this->belongsTo(Wallet::class); }
    /** Handles user for the wallet coin lot workflow. */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    /** Handles source transaction for the wallet coin lot workflow. */
    public function sourceTransaction(): BelongsTo { return $this->belongsTo(WalletTransaction::class, 'source_transaction_id'); }
    /** Handles origin lot for the wallet coin lot workflow. */
    public function originLot(): BelongsTo { return $this->belongsTo(self::class, 'origin_lot_id'); }
    /** Handles consumptions for the wallet coin lot workflow. */
    public function consumptions(): HasMany { return $this->hasMany(WalletCoinConsumption::class); }
}
