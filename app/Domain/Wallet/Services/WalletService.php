<?php
namespace App\Domain\Wallet\Services;

use App\Domain\Wallet\Exceptions\WalletException;
use App\Enums\WalletEntryDirection;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Defines the WalletService class and its project responsibilities. */
class WalletService
{
    /** Initializes the WalletService instance and its dependencies. */
    public function __construct(private readonly CoinLotService $lots) {}
    /** Handles wallet for for the wallet service workflow. */
    public function walletFor(User $user, bool $lock = false): Wallet
    {
        $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance_coins' => 0, 'reserved_coins' => 0]);
        return $lock ? Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail() : $wallet;
    }

    /** Handles credit for the wallet service workflow. */
    public function credit(User $user, int $coins, WalletTransactionType $type, string $idempotencyKey, ?string $referenceType = null, ?string $referenceId = null, array $metadata = []): WalletTransaction
    {
        if ($coins <= 0) throw new WalletException('Credit amount must be greater than zero.', 'coins');
        $existing = WalletTransaction::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            if ($existing->initiated_by_user_id !== $user->id) throw new WalletException('Idempotency key is already owned by another wallet operation.', 'idempotencyKey');
            return $existing->load('entries');
        }

        return DB::transaction(/** Inline callback for this operation. */ function () use ($user,$coins,$type,$idempotencyKey,$referenceType,$referenceId,$metadata): WalletTransaction {
            $existing = WalletTransaction::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->initiated_by_user_id !== $user->id) throw new WalletException('Idempotency key is already owned by another wallet operation.', 'idempotencyKey');
                return $existing->load('entries');
            }
            $wallet = $this->walletFor($user, true);
            $this->lots->ensureOpeningCoverage($wallet);
            $wallet->balance_coins += $coins;
            $wallet->save();
            $tx = WalletTransaction::create([
                'public_id'=>(string) Str::ulid(),'initiated_by_user_id'=>$user->id,'type'=>$type,'status'=>'posted','idempotency_key'=>$idempotencyKey,
                'reference_type'=>$referenceType,'reference_id'=>$referenceId,'metadata'=>$metadata,'occurred_at'=>now(),
            ]);
            $tx->entries()->create([
                'wallet_id'=>$wallet->id,'user_id'=>$user->id,'direction'=>WalletEntryDirection::Credit,'coins'=>$coins,
                'balance_after_coins'=>$wallet->balance_coins,'metadata'=>$metadata,
            ]);
            $this->lots->recordCredit($wallet,$tx,$coins,$type,$metadata);
            return $tx->load('entries');
        }, 3);
    }

    /** Handles debit for the wallet service workflow. */
    public function debit(User $user, int $coins, WalletTransactionType $type, string $idempotencyKey, ?string $referenceType = null, ?string $referenceId = null, array $metadata = [], bool $allowOverdraft = false): WalletTransaction
    {
        if ($coins <= 0) throw new WalletException('Debit amount must be greater than zero.', 'coins');
        $existing = WalletTransaction::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            if ($existing->initiated_by_user_id !== $user->id) throw new WalletException('Idempotency key is already owned by another wallet operation.', 'idempotencyKey');
            return $existing->load('entries');
        }

        return DB::transaction(/** Inline callback for this operation. */ function () use ($user,$coins,$type,$idempotencyKey,$referenceType,$referenceId,$metadata,$allowOverdraft): WalletTransaction {
            $existing = WalletTransaction::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->initiated_by_user_id !== $user->id) throw new WalletException('Idempotency key is already owned by another wallet operation.', 'idempotencyKey');
                return $existing->load('entries');
            }
            $wallet = $this->walletFor($user, true);
            $this->lots->ensureOpeningCoverage($wallet);
            if (! $allowOverdraft && $this->lots->spendableCoins($wallet) < $coins) throw new WalletException('Insufficient available VSN Coins.', 'coins');
            $wallet->balance_coins -= $coins;
            $wallet->save();
            $tx = WalletTransaction::create([
                'public_id'=>(string) Str::ulid(),'initiated_by_user_id'=>$user->id,'type'=>$type,'status'=>'posted','idempotency_key'=>$idempotencyKey,
                'reference_type'=>$referenceType,'reference_id'=>$referenceId,'metadata'=>$metadata,'occurred_at'=>now(),
            ]);
            $tx->entries()->create([
                'wallet_id'=>$wallet->id,'user_id'=>$user->id,'direction'=>WalletEntryDirection::Debit,'coins'=>$coins,
                'balance_after_coins'=>$wallet->balance_coins,'metadata'=>$metadata,
            ]);
            $this->lots->consume($wallet,$tx,$coins);
            return $tx->load('entries');
        }, 3);
    }
}
