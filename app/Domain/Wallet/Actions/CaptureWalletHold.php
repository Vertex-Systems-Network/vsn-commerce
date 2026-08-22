<?php
namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\Exceptions\WalletException;
use App\Domain\Wallet\Services\CoinLotService;
use App\Enums\WalletEntryDirection;
use App\Enums\WalletHoldStatus;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletHold;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Defines the CaptureWalletHold class and its project responsibilities. */
class CaptureWalletHold
{
    /** Initializes the CaptureWalletHold instance and its dependencies. */
    public function __construct(private readonly CoinLotService $lots) {}
    /** Executes the capture wallet hold operation. */
    public function execute(User $user, WalletHold $hold, string $idempotencyKey, string $referenceType, string $referenceId): WalletTransaction
    {
        $existing = WalletTransaction::query()->where('idempotency_key',$idempotencyKey)->first();
        if ($existing) {
            if ($existing->initiated_by_user_id !== $user->id) throw new WalletException('Idempotency key is already owned by another wallet capture.', 'idempotencyKey');
            return $existing->load('entries');
        }
        return DB::transaction(/** Inline callback for this operation. */ function () use ($user,$hold,$idempotencyKey,$referenceType,$referenceId): WalletTransaction {
            $hold = WalletHold::query()->whereKey($hold->id)->lockForUpdate()->firstOrFail();
            if ($hold->user_id !== $user->id) throw new WalletException('Wallet hold ownership mismatch.');
            if ($hold->capture_transaction_id) return WalletTransaction::query()->findOrFail($hold->capture_transaction_id)->load('entries');
            if ($hold->status !== WalletHoldStatus::Active) throw new WalletException('Wallet hold is no longer active.');
            if ($hold->expires_at?->isPast()) throw new WalletException('Wallet hold expired before capture.');
            $wallet = Wallet::query()->whereKey($hold->wallet_id)->lockForUpdate()->firstOrFail();
            $this->lots->ensureOpeningCoverage($wallet);
            if ($wallet->reserved_coins < $hold->coins || $wallet->balance_coins < $hold->coins) throw new WalletException('Wallet balance is inconsistent with its active hold.');
            $wallet->reserved_coins -= $hold->coins; $wallet->balance_coins -= $hold->coins; $wallet->save();
            $tx = WalletTransaction::create([
                'public_id'=>(string)Str::ulid(),'initiated_by_user_id'=>$user->id,'type'=>WalletTransactionType::CheckoutRedemption,'status'=>'posted',
                'idempotency_key'=>$idempotencyKey,'reference_type'=>$referenceType,'reference_id'=>$referenceId,'occurred_at'=>now(),
            ]);
            $tx->entries()->create(['wallet_id'=>$wallet->id,'user_id'=>$user->id,'direction'=>WalletEntryDirection::Debit,'coins'=>$hold->coins,'balance_after_coins'=>$wallet->balance_coins]);
            $this->lots->consume($wallet,$tx,(int)$hold->coins,true);
            $hold->update(['status'=>WalletHoldStatus::Captured,'capture_transaction_id'=>$tx->id,'captured_at'=>now()]);
            return $tx->load('entries');
        },3);
    }
}
