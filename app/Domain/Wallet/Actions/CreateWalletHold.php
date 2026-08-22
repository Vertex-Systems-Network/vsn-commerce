<?php
namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\Exceptions\WalletException;
use App\Domain\Wallet\Services\WalletService;
use App\Domain\Wallet\Services\CoinLotService;
use App\Enums\WalletHoldStatus;
use App\Models\User;
use App\Models\WalletHold;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Defines the CreateWalletHold class and its project responsibilities. */
class CreateWalletHold
{
    /** Initializes the CreateWalletHold instance and its dependencies. */
    public function __construct(private readonly WalletService $wallets, private readonly CoinLotService $lots) {}
    /** Executes the create wallet hold operation. */
    public function execute(User $user, int $coins, string $idempotencyKey, string $referenceType, string $referenceId, $expiresAt = null): WalletHold
    {
        if ($coins <= 0) throw new WalletException('Hold amount must be greater than zero.', 'coins');
        $existing = WalletHold::query()->where('idempotency_key',$idempotencyKey)->first();
        if ($existing) {
            if ($existing->user_id !== $user->id) throw new WalletException('Idempotency key is already owned by another wallet hold.', 'idempotencyKey');
            return $existing;
        }
        return DB::transaction(/** Inline callback for this operation. */ function () use ($user,$coins,$idempotencyKey,$referenceType,$referenceId,$expiresAt): WalletHold {
            $existing = WalletHold::query()->where('idempotency_key',$idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->user_id !== $user->id) throw new WalletException('Idempotency key is already owned by another wallet hold.', 'idempotencyKey');
                return $existing;
            }
            $wallet = $this->wallets->walletFor($user, true);
            if ($this->lots->spendableCoins($wallet) < $coins) throw new WalletException('Insufficient available VSN Coins.', 'coinRedemptionCoins');
            $wallet->reserved_coins += $coins; $wallet->save();
            return WalletHold::create([
                'public_id'=>(string)Str::ulid(),'wallet_id'=>$wallet->id,'user_id'=>$user->id,'coins'=>$coins,'status'=>WalletHoldStatus::Active,
                'idempotency_key'=>$idempotencyKey,'reference_type'=>$referenceType,'reference_id'=>$referenceId,'expires_at'=>$expiresAt,
            ]);
        }, 3);
    }
}
