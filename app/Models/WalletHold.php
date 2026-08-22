<?php
namespace App\Models;

use App\Enums\WalletHoldStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Defines the WalletHold class and its project responsibilities. */
class WalletHold extends Model
{
    use HasFactory;
    protected $fillable = ['public_id','wallet_id','user_id','coins','status','idempotency_key','reference_type','reference_id','capture_transaction_id','expires_at','captured_at','released_at','metadata'];
    /** Handles casts for the wallet hold workflow. */
    protected function casts(): array { return ['coins'=>'integer','status'=>WalletHoldStatus::class,'expires_at'=>'datetime','captured_at'=>'datetime','released_at'=>'datetime','metadata'=>'array']; }
    /** Handles wallet for the wallet hold workflow. */
    public function wallet(): BelongsTo { return $this->belongsTo(Wallet::class); }
    /** Handles user for the wallet hold workflow. */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    /** Handles capture transaction for the wallet hold workflow. */
    public function captureTransaction(): BelongsTo { return $this->belongsTo(WalletTransaction::class, 'capture_transaction_id'); }
}
