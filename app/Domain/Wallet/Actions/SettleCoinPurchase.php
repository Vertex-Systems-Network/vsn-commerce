<?php
namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\Services\WalletService;
use App\Enums\CoinPurchaseStatus;
use App\Enums\WalletTransactionType;
use App\Models\CoinPurchase;
use App\Models\PaymentIntent;
use App\Models\WalletTransaction;

/** Defines the SettleCoinPurchase class and its project responsibilities. */
class SettleCoinPurchase
{
    /** Initializes the SettleCoinPurchase instance and its dependencies. */
    public function __construct(private readonly WalletService $wallets) {}
    /** Executes the settle coin purchase operation. */
    public function execute(PaymentIntent $intent): WalletTransaction
    {
        $purchase = CoinPurchase::query()->where('payment_intent_id',$intent->id)->firstOrFail();
        if ($purchase->wallet_transaction_id) return WalletTransaction::query()->findOrFail($purchase->wallet_transaction_id)->load('entries');
        $tx = $this->wallets->credit($purchase->user()->firstOrFail(),$purchase->coins,WalletTransactionType::CoinPurchase,"coin-purchase-credit:{$purchase->public_id}",'coin_purchase',$purchase->public_id,['payment_intent_id'=>$intent->public_id]);
        $purchase->update(['status'=>CoinPurchaseStatus::Paid,'wallet_transaction_id'=>$tx->id,'paid_at'=>now()]);
        return $tx;
    }
}
