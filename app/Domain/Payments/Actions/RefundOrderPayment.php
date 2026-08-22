<?php
namespace App\Domain\Payments\Actions;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Domain\Payments\Services\PaymentGatewayManager;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Models\Order;
use App\Models\PaymentIntent;
use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
/** Defines the RefundOrderPayment class and its project responsibilities. */
class RefundOrderPayment
{
    /** Initializes the RefundOrderPayment instance and its dependencies. */
    public function __construct(private readonly PaymentGatewayManager $gateways) {}
    /** Executes the refund order payment operation. */
    public function execute(Order $order, int $amountMinor, string $idempotencyKey, string $referenceId): ?PaymentTransaction
    {
        if ($amountMinor <= 0) return null;
        $existing=PaymentTransaction::query()->where('idempotency_key',$idempotencyKey)->first();
        if ($existing && in_array($existing->status,[PaymentTransactionStatus::Succeeded,PaymentTransactionStatus::Pending],true)) return $existing;
        if ($order->payment_method === 'cod') return null;
        $intent=PaymentIntent::query()->where('order_id',$order->id)->whereNotNull('paid_at')->latest('paid_at')->first();
        if (! $intent) throw new PaymentException('No captured provider payment is available for this refund.');
        return DB::transaction(/** Inline callback for this operation. */ function () use ($order,$intent,$amountMinor,$idempotencyKey,$referenceId): PaymentTransaction {
            $intent=PaymentIntent::query()->whereKey($intent->id)->lockForUpdate()->firstOrFail();
            $existing=PaymentTransaction::query()->where('idempotency_key',$idempotencyKey)->lockForUpdate()->first();
            if ($existing && in_array($existing->status,[PaymentTransactionStatus::Succeeded,PaymentTransactionStatus::Pending],true)) return $existing;
            $captured=(int)PaymentTransaction::query()->where('payment_intent_id',$intent->id)->where('type',PaymentTransactionType::Capture->value)->where('status',PaymentTransactionStatus::Succeeded->value)->sum('amount_minor');
            $refunded=(int)PaymentTransaction::query()->where('payment_intent_id',$intent->id)->where('type',PaymentTransactionType::Refund->value)->where('status',PaymentTransactionStatus::Succeeded->value)->when($existing,/** Inline callback for this operation. */ fn($q)=>$q->where('id','!=',$existing->id))->sum('amount_minor');
            if ($amountMinor > max(0,$captured-$refunded)) throw new PaymentException('Refund exceeds the remaining captured payment amount.');
            $result=$this->gateways->gateway($intent->provider)->refund($intent,$amountMinor,$idempotencyKey);
            $values=[
                'payment_intent_id'=>$intent->id,'order_id'=>$order->id,'provider'=>$intent->provider,
                'type'=>PaymentTransactionType::Refund,'status'=>match($result->status){'succeeded'=>PaymentTransactionStatus::Succeeded,'pending'=>PaymentTransactionStatus::Pending,default=>PaymentTransactionStatus::Failed},'currency'=>$order->currency,'amount_minor'=>$amountMinor,
                'provider_transaction_id'=>$result->providerTransactionId,'idempotency_key'=>$idempotencyKey,
                'metadata'=>array_merge($result->metadata,['refund_reference'=>$referenceId]),'occurred_at'=>now(),
            ];
            if($existing){$existing->update($values);return $existing->fresh();}
            return PaymentTransaction::create(array_merge(['public_id'=>(string)Str::ulid()],$values));
        },3);
    }
}
