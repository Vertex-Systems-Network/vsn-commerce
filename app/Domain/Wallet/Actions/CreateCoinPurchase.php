<?php
namespace App\Domain\Wallet\Actions;

use App\Domain\Payments\Exceptions\PaymentException;
use App\Domain\Payments\Services\PaymentGatewayManager;
use App\Domain\Risk\Services\RiskGate;
use App\Domain\Risk\Exceptions\RiskBlockedException;
use App\Domain\Wallet\Exceptions\WalletException;
use App\Enums\CoinPurchaseStatus;
use App\Enums\PaymentIntentStatus;
use App\Models\CoinPurchase;
use App\Models\PaymentIntent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Defines the CreateCoinPurchase class and its project responsibilities. */
class CreateCoinPurchase
{
    /** Initializes the CreateCoinPurchase instance and its dependencies. */
    public function __construct(private readonly PaymentGatewayManager $gateways, private readonly RiskGate $risk) {}

    /** Executes the create coin purchase operation. */
    public function execute(User $user, int $coins, string $idempotencyKey): CoinPurchase
    {
        $perRupee = (int) config('vsn.coins_per_rupee', 70);
        if ($coins < $perRupee || $coins % $perRupee !== 0) {
            throw new WalletException("Coin purchases must be in increments of {$perRupee} coins.", 'coins');
        }
        $max = (int) config('vsn.wallet.max_purchase_coins', 7_000_000);
        if ($coins > $max) throw new WalletException('Coin purchase exceeds the allowed limit.', 'coins');
        if (! $this->gateways->methodEnabled('card')) throw new PaymentException('Online card payments are not enabled for coin purchases.', 'paymentMethod');
        $provider = $this->gateways->providerForMethod('card');
        $amountMinor = intdiv($coins, $perRupee) * 100;

        $existing = CoinPurchase::query()->where('idempotency_key',$idempotencyKey)->first();
        if ($existing) {
            if ($existing->user_id !== $user->id) throw new WalletException('Idempotency key is already owned by another coin purchase.', 'idempotencyKey');
            return $existing->load('paymentIntent');
        }
        try { $this->risk->assertAllowed($user,'wallet'); $this->risk->payment($user); }
        catch (RiskBlockedException $e) { throw new WalletException($e->getMessage(), 'risk'); }

        try {
            [$purchase,$intent] = DB::transaction(/** Inline callback for this operation. */ function () use ($user,$coins,$idempotencyKey,$provider,$amountMinor): array {
                $existing = CoinPurchase::query()->where('idempotency_key',$idempotencyKey)->lockForUpdate()->first();
                if ($existing) {
                    if ($existing->user_id !== $user->id) throw new WalletException('Idempotency key is already owned by another coin purchase.', 'idempotencyKey');
                    return [$existing, $existing->paymentIntent];
                }
                $purchase = CoinPurchase::create([
                    'public_id'=>(string)Str::ulid(),'user_id'=>$user->id,'coins'=>$coins,'currency'=>config('vsn.currency','PKR'),'amount_minor'=>$amountMinor,
                    'status'=>CoinPurchaseStatus::Pending,'idempotency_key'=>$idempotencyKey,
                ]);
                $intent = PaymentIntent::create([
                    'public_id'=>(string)Str::ulid(),'user_id'=>$user->id,'checkout_session_id'=>null,'order_id'=>null,
                    'idempotency_key'=>"coin-payment:{$idempotencyKey}",'purpose'=>'coin_purchase','reference_type'=>'coin_purchase','reference_id'=>$purchase->public_id,
                    'provider'=>$provider,'payment_method'=>'card','status'=>PaymentIntentStatus::Creating,'currency'=>$purchase->currency,'amount_minor'=>$amountMinor,
                    'expires_at'=>now()->addMinutes((int)config('vsn.wallet.coin_purchase_payment_minutes',30)),
                    'metadata'=>['coin_purchase_id'=>$purchase->public_id,'coins'=>$coins],
                ]);
                $purchase->update(['payment_intent_id'=>$intent->id]);
                return [$purchase,$intent];
            },3);
        } catch (QueryException $e) {
            $purchase = CoinPurchase::query()->where('idempotency_key',$idempotencyKey)->first();
            if (! $purchase) throw $e;
            if ($purchase->user_id !== $user->id) throw new WalletException('Idempotency key is already owned by another coin purchase.', 'idempotencyKey');
            return $purchase->load('paymentIntent');
        }

        if (! $intent || $intent->status !== PaymentIntentStatus::Creating) return $purchase->fresh()->load('paymentIntent');
        try {
            $result = $this->gateways->gateway($provider)->createIntent($intent);
            $intent->update(['provider_payment_id'=>$result->providerPaymentId,'client_action'=>$result->clientAction,'status'=>PaymentIntentStatus::RequiresAction,'metadata'=>array_merge($intent->metadata??[],$result->metadata)]);
            $purchase->update(['status'=>CoinPurchaseStatus::RequiresAction]);
        } catch (Throwable $e) {
            $intent->update(['status'=>PaymentIntentStatus::Failed,'failed_at'=>now(),'metadata'=>array_merge($intent->metadata??[],['provider_error'=>$e->getMessage()])]);
            $purchase->update(['status'=>CoinPurchaseStatus::Failed]);
            throw new PaymentException('Payment provider could not initialize the coin purchase.');
        }
        return $purchase->fresh()->load('paymentIntent');
    }
}
