<?php
namespace App\Domain\Wallet\Actions;

use App\Domain\Gifts\Actions\RecordGiftSenderProgress;
use App\Domain\Wallet\Exceptions\WalletException;
use App\Domain\Wallet\Services\WalletService;
use App\Domain\Wallet\Services\CoinLotService;
use App\Domain\Risk\Services\RiskGate;
use App\Domain\Risk\Exceptions\RiskBlockedException;
use App\Enums\WalletEntryDirection;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Defines the TransferCoins class and its project responsibilities. */
class TransferCoins
{
    /** Initializes the TransferCoins instance and its dependencies. */
    public function __construct(private readonly WalletService $wallets, private readonly CoinLotService $lots, private readonly RecordGiftSenderProgress $giftProgress, private readonly RiskGate $risk) {}

    /** Executes the transfer coins operation. */
    public function execute(User $sender, User $recipient, int $coins, string $idempotencyKey, bool $gift = true): WalletTransaction
    {
        if ($sender->id === $recipient->id) throw new WalletException('You cannot send coins to yourself.', 'recipient');
        if ($coins <= 0) throw new WalletException('Coins must be greater than zero.', 'coins');
        $existing = WalletTransaction::query()->where('idempotency_key',$idempotencyKey)->first();
        if ($existing) {
            if ($existing->initiated_by_user_id !== $sender->id) throw new WalletException('Idempotency key is already owned by another wallet operation.', 'idempotencyKey');
            $tx = $existing->load('entries');
            if ($gift) $this->recordGiftProgress($sender, $coins, $tx);
            return $tx;
        }
        try { $this->risk->walletTransfer($sender, $coins); }
        catch (RiskBlockedException $e) { throw new WalletException($e->getMessage(), 'risk'); }

        $tx = DB::transaction(/** Inline callback for this operation. */ function () use ($sender,$recipient,$coins,$idempotencyKey,$gift): WalletTransaction {
            $existing = WalletTransaction::query()->where('idempotency_key',$idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->initiated_by_user_id !== $sender->id) throw new WalletException('Idempotency key is already owned by another wallet operation.', 'idempotencyKey');
                return $existing->load('entries');
            }
            $senderWallet = $this->wallets->walletFor($sender);
            $recipientWallet = $this->wallets->walletFor($recipient);
            $ids = collect([$senderWallet->id,$recipientWallet->id])->sort()->values();
            $locked = Wallet::query()->whereIn('id',$ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $from = $locked[$senderWallet->id]; $to = $locked[$recipientWallet->id];
            $this->lots->ensureOpeningCoverage($from); $this->lots->ensureOpeningCoverage($to);
            if ($this->lots->spendableCoins($from) < $coins) throw new WalletException('Insufficient available VSN Coins.', 'coins');
            $from->balance_coins -= $coins; $from->save();
            $to->balance_coins += $coins; $to->save();
            $tx = WalletTransaction::create([
                'public_id'=>(string) Str::ulid(),'initiated_by_user_id'=>$sender->id,'type'=>$gift ? WalletTransactionType::Gift : WalletTransactionType::Transfer,
                'status'=>'posted','idempotency_key'=>$idempotencyKey,'reference_type'=>'user','reference_id'=>(string)$recipient->id,
                'metadata'=>['recipient_user_id'=>$recipient->id],'occurred_at'=>now(),
            ]);
            $tx->entries()->create(['wallet_id'=>$from->id,'user_id'=>$sender->id,'direction'=>WalletEntryDirection::Debit,'coins'=>$coins,'balance_after_coins'=>$from->balance_coins]);
            $tx->entries()->create(['wallet_id'=>$to->id,'user_id'=>$recipient->id,'direction'=>WalletEntryDirection::Credit,'coins'=>$coins,'balance_after_coins'=>$to->balance_coins]);
            $allocations=$this->lots->consume($from,$tx,$coins);
            foreach($allocations as $allocation){ $this->lots->recordCredit($to,$tx,$allocation['coins'],$gift ? WalletTransactionType::Gift : WalletTransactionType::Transfer,['transferred_from_user_id'=>$sender->id],$allocation['lot']); }
            return $tx->load('entries');
        }, 3);
        if ($gift) $this->recordGiftProgress($sender, $coins, $tx);
        return $tx;
    }

    /** Handles record gift progress for the transfer coins workflow. */
    private function recordGiftProgress(User $sender, int $coins, WalletTransaction $transaction): void
    {
        $this->giftProgress->execute($sender, $coins, "coin-gift:{$transaction->public_id}", 'wallet_transaction', $transaction->public_id, 'coin_gift');
    }
}

