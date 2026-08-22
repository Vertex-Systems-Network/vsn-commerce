<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the WalletCoinConsumption class and its project responsibilities. */
class WalletCoinConsumption extends Model
{
    protected $fillable = ['debit_transaction_id','wallet_coin_lot_id','coins','restored_coins'];
    /** Handles casts for the wallet coin consumption workflow. */
    protected function casts(): array { return ['coins'=>'integer','restored_coins'=>'integer']; }
    /** Handles debit transaction for the wallet coin consumption workflow. */
    public function debitTransaction(): BelongsTo { return $this->belongsTo(WalletTransaction::class, 'debit_transaction_id'); }
    /** Handles lot for the wallet coin consumption workflow. */
    public function lot(): BelongsTo { return $this->belongsTo(WalletCoinLot::class, 'wallet_coin_lot_id'); }
}
