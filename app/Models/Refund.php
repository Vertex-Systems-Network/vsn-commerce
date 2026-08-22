<?php
namespace App\Models;
use App\Enums\RefundStatus;
use App\Enums\ReturnResolution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
/** Defines the Refund class and its project responsibilities. */
class Refund extends Model
{
    protected $fillable=['public_id','return_request_id','order_id','status','resolution','currency','amount_minor','tax_refund_minor','cash_refund_minor','coin_refund_minor','coin_refund_coins','idempotency_key','attempt_count','last_attempt_at','payment_refund_transaction_id','wallet_refund_transaction_id','manual_reference','processed_at','metadata'];
    /** Handles casts for the refund workflow. */
    protected function casts(): array { return ['status'=>RefundStatus::class,'resolution'=>ReturnResolution::class,'amount_minor'=>'integer','tax_refund_minor'=>'integer','cash_refund_minor'=>'integer','coin_refund_minor'=>'integer','coin_refund_coins'=>'integer','attempt_count'=>'integer','last_attempt_at'=>'datetime','processed_at'=>'datetime','metadata'=>'array']; }
    /** Returns route key name. */
    public function getRouteKeyName(): string { return 'public_id'; }
    /** Handles request for the refund workflow. */
    public function request(): BelongsTo { return $this->belongsTo(ReturnRequest::class,'return_request_id'); }
    /** Handles order for the refund workflow. */
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    /** Handles payment transaction for the refund workflow. */
    public function paymentTransaction(): BelongsTo { return $this->belongsTo(PaymentTransaction::class,'payment_refund_transaction_id'); }
    /** Handles wallet transaction for the refund workflow. */
    public function walletTransaction(): BelongsTo { return $this->belongsTo(WalletTransaction::class,'wallet_refund_transaction_id'); }
    /** Handles vendor adjustments for the refund workflow. */
    public function vendorAdjustments(): HasMany { return $this->hasMany(VendorRefundAdjustment::class); }
    /** Handles events for the refund workflow. */
    public function events(): HasMany { return $this->hasMany(RefundEvent::class)->orderBy('occurred_at'); }
}
