<?php
namespace App\Models;

use App\Enums\AffiliateCommissionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Defines the AffiliateCommission class and its project responsibilities. */
class AffiliateCommission extends Model
{
    use HasFactory;
    protected $fillable = ['public_id','order_id','buyer_id','beneficiary_id','level_no','rate_bps','currency','eligible_spend_minor','reward_coins','status','available_at','credited_at','reversed_at','wallet_transaction_id','reversal_wallet_transaction_id','metadata'];
    /** Handles casts for the affiliate commission workflow. */
    protected function casts(): array { return ['status'=>AffiliateCommissionStatus::class,'level_no'=>'integer','rate_bps'=>'integer','eligible_spend_minor'=>'integer','reward_coins'=>'integer','available_at'=>'datetime','credited_at'=>'datetime','reversed_at'=>'datetime','metadata'=>'array']; }
    /** Returns route key name. */
    public function getRouteKeyName(): string { return 'public_id'; }
    /** Handles order for the affiliate commission workflow. */
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    /** Handles buyer for the affiliate commission workflow. */
    public function buyer(): BelongsTo { return $this->belongsTo(User::class, 'buyer_id'); }
    /** Handles beneficiary for the affiliate commission workflow. */
    public function beneficiary(): BelongsTo { return $this->belongsTo(User::class, 'beneficiary_id'); }
    /** Handles wallet transaction for the affiliate commission workflow. */
    public function walletTransaction(): BelongsTo { return $this->belongsTo(WalletTransaction::class, 'wallet_transaction_id'); }
    /** Handles reversal wallet transaction for the affiliate commission workflow. */
    public function reversalWalletTransaction(): BelongsTo { return $this->belongsTo(WalletTransaction::class, 'reversal_wallet_transaction_id'); }
    /** Handles refund adjustments for the affiliate commission workflow. */
    public function refundAdjustments(): HasMany { return $this->hasMany(AffiliateCommissionRefund::class); }
}
