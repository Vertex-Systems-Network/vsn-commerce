<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Checkout\Exceptions\CheckoutValidationException;
use App\Domain\Wallet\Services\WalletService;
use App\Models\User;

/** Defines the CoinRedemptionResolver class and its project responsibilities. */
class CoinRedemptionResolver
{
    /** Initializes the CoinRedemptionResolver instance and its dependencies. */
    public function __construct(private readonly WalletService $wallets) {}

    /** @return array{coins:int, amountMinor:int} */
    public function resolve(User $user, int $requestedCoins, int $payableMinor): array
    {
        if ($requestedCoins <= 0) return ['coins'=>0,'amountMinor'=>0];
        $perRupee=(int)config('vsn.coins_per_rupee',70);
        if ($requestedCoins % $perRupee !== 0) {
            throw new CheckoutValidationException("Coin redemption must be in increments of {$perRupee} coins.", 'coinRedemptionCoins');
        }
        $wallet=$this->wallets->walletFor($user);
        if ($wallet->availableCoins() < $requestedCoins) {
            throw new CheckoutValidationException('You do not have enough available VSN Coins.', 'coinRedemptionCoins');
        }
        $amountMinor=intdiv($requestedCoins,$perRupee)*100;
        if ($amountMinor > $payableMinor) {
            throw new CheckoutValidationException('Coin redemption cannot exceed the payable checkout total.', 'coinRedemptionCoins');
        }
        return ['coins'=>$requestedCoins,'amountMinor'=>$amountMinor];
    }
}
