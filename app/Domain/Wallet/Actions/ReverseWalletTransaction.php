<?php
namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\Exceptions\WalletException;
use App\Domain\Wallet\Services\CoinLotService;
use App\Enums\WalletEntryDirection;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Defines the ReverseWalletTransaction class and its project responsibilities. */
class ReverseWalletTransaction
{
    /** Initializes the ReverseWalletTransaction instance and its dependencies. */
    public function __construct(private readonly CoinLotService $lots) {}
    /** Executes the reverse wallet transaction operation. */
    public function execute(?User $initiator, WalletTransaction $original, string $idempotencyKey, ?string $referenceType = null, ?string $referenceId = null, bool $allowOverdraft = false): WalletTransaction
    {
        $existing = WalletTransaction::query()->where('idempotency_key',$idempotencyKey)->first();
        if ($existing) return $existing->load('entries');
        return DB::transaction(/** Inline callback for this operation. */ function () use ($initiator,$original,$idempotencyKey,$referenceType,$referenceId,$allowOverdraft): WalletTransaction {
            $original = WalletTransaction::query()->whereKey($original->id)->with('entries')->lockForUpdate()->firstOrFail();
            $existing = WalletTransaction::query()->where('idempotency_key',$idempotencyKey)->first();
            if ($existing) return $existing->load('entries');
            if (WalletTransaction::query()->where('reversal_of_transaction_id',$original->id)->exists()) throw new WalletException('This wallet transaction has already been reversed.');
            $walletIds = $original->entries->pluck('wallet_id')->unique()->sort()->values();
            $wallets = Wallet::query()->whereIn('id',$walletIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($wallets as $wallet) $this->lots->ensureOpeningCoverage($wallet);
            foreach ($original->entries as $entry) {
                if (! $allowOverdraft && $entry->direction === WalletEntryDirection::Credit) {
                    $wallet = $wallets[$entry->wallet_id];
                    if ($wallet->availableCoins() < $entry->coins) throw new WalletException('Reversal would overdraw a recipient wallet.');
                }
            }
            $tx = WalletTransaction::create([
                'public_id'=>(string)Str::ulid(),'initiated_by_user_id'=>$initiator?->id,'type'=>WalletTransactionType::Reversal,'status'=>'posted','idempotency_key'=>$idempotencyKey,
                'reference_type'=>$referenceType ?: $original->reference_type,'reference_id'=>$referenceId ?: $original->reference_id,'reversal_of_transaction_id'=>$original->id,
                'metadata'=>['original_transaction'=>$original->public_id],'occurred_at'=>now(),
            ]);
            foreach ($original->entries as $entry) {
                $wallet = $wallets[$entry->wallet_id];
                $direction = $entry->direction === WalletEntryDirection::Credit ? WalletEntryDirection::Debit : WalletEntryDirection::Credit;
                $wallet->balance_coins += $direction === WalletEntryDirection::Credit ? $entry->coins : -$entry->coins;
                $wallet->save();
                $tx->entries()->create(['wallet_id'=>$wallet->id,'user_id'=>$entry->user_id,'direction'=>$direction,'coins'=>$entry->coins,'balance_after_coins'=>$wallet->balance_coins]);
                if ($direction === WalletEntryDirection::Debit) $this->lots->consume($wallet,$tx,(int)$entry->coins);
                else $this->lots->restoreDebit($original,$wallet,$tx);
            }
            return $tx->load('entries');
        },3);
    }
}
