<?php
namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\Services\WalletService;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\WalletTransaction;

/** Defines the SpendCoins class and its project responsibilities. */
class SpendCoins
{
    /** Initializes the SpendCoins instance and its dependencies. */
    public function __construct(private readonly WalletService $wallets) {}
    /** Handles for game entry for the spend coins workflow. */
    public function forGameEntry(User $user, int $coins, string $gameEntryId, string $idempotencyKey, array $metadata = []): WalletTransaction
    {
        return $this->wallets->debit($user,$coins,WalletTransactionType::GameEntry,$idempotencyKey,'game_entry',$gameEntryId,$metadata);
    }
}
