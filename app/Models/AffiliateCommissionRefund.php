<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** Defines the AffiliateCommissionRefund class and its project responsibilities. */
class AffiliateCommissionRefund extends Model
{
    protected $fillable=['affiliate_commission_id','refund_id','refunded_eligible_minor','reversed_coins','wallet_transaction_id'];
    /** Handles casts for the affiliate commission refund workflow. */
    protected function casts(): array { return ['refunded_eligible_minor'=>'integer','reversed_coins'=>'integer']; }
    /** Handles commission for the affiliate commission refund workflow. */
    public function commission(): BelongsTo { return $this->belongsTo(AffiliateCommission::class,'affiliate_commission_id'); }
    /** Handles refund for the affiliate commission refund workflow. */
    public function refund(): BelongsTo { return $this->belongsTo(Refund::class); }
    /** Handles wallet transaction for the affiliate commission refund workflow. */
    public function walletTransaction(): BelongsTo { return $this->belongsTo(WalletTransaction::class); }
}
