<?php
namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\Services\WalletService;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\WalletTransaction;

/** Defines the CreditWalletRefund class and its project responsibilities. */
class CreditWalletRefund
{
    /** Initializes the CreditWalletRefund instance and its dependencies. */
    public function __construct(private readonly WalletService $wallets) {}
    /** Executes the credit wallet refund operation. */
    public function execute(User $user, int $coins, string $referenceType, string $referenceId, string $idempotencyKey, array $metadata = []): WalletTransaction
    {
        return $this->wallets->credit($user,$coins,WalletTransactionType::Refund,$idempotencyKey,$referenceType,$referenceId,$metadata);
    }
}
