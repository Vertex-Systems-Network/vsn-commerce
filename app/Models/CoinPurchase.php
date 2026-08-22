<?php
namespace App\Models;

use App\Enums\CoinPurchaseStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the CoinPurchase class and its project responsibilities. */
class CoinPurchase extends Model
{
    use HasFactory;
    protected $fillable = ['public_id','user_id','coins','currency','amount_minor','status','idempotency_key','payment_intent_id','wallet_transaction_id','paid_at','metadata'];
    /** Handles casts for the coin purchase workflow. */
    protected function casts(): array { return ['coins'=>'integer','amount_minor'=>'integer','status'=>CoinPurchaseStatus::class,'paid_at'=>'datetime','metadata'=>'array']; }
    /** Returns route key name. */
    public function getRouteKeyName(): string { return 'public_id'; }
    /** Handles user for the coin purchase workflow. */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    /** Handles payment intent for the coin purchase workflow. */
    public function paymentIntent(): BelongsTo { return $this->belongsTo(PaymentIntent::class); }
    /** Handles wallet transaction for the coin purchase workflow. */
    public function walletTransaction(): BelongsTo { return $this->belongsTo(WalletTransaction::class); }
}
