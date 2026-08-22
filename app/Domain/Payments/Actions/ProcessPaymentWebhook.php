<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Checkout\Actions\PlaceOrder;
use App\Domain\Payments\Data\VerifiedWebhook;
use App\Domain\Payments\Services\PaymentGatewayManager;
use App\Domain\Risk\Services\RiskRecorder;
use App\Domain\Wallet\Actions\SettleCoinPurchase;
use App\Enums\CheckoutStatus;
use App\Enums\CoinPurchaseStatus;
use App\Enums\PaymentIntentStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\PaymentWebhookStatus;
use App\Models\CheckoutSession;
use App\Models\CoinPurchase;
use App\Models\PaymentIntent;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Defines the ProcessPaymentWebhook class and its project responsibilities. */
class ProcessPaymentWebhook
{
    /** Initializes the ProcessPaymentWebhook instance and its dependencies. */
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly PlaceOrder $placeOrder,
        private readonly SettleCoinPurchase $settleCoinPurchase,
        private readonly RiskRecorder $riskEvents,
    ) {}

    /** Executes the process payment webhook operation. */
    public function execute(string $provider, string $rawPayload, array $headers): PaymentWebhookEvent
    {
        $verified = $this->gateways->gateway($provider)->verifyWebhook($rawPayload, $headers);

        try {
            $event = PaymentWebhookEvent::query()->firstOrCreate(
                ['provider'=>$provider,'provider_event_id'=>$verified->eventId],
                ['event_type'=>$verified->eventType,'status'=>PaymentWebhookStatus::Received,'payload_sha256'=>hash('sha256',$rawPayload),'payload'=>$verified->payload,'received_at'=>now()],
            );
        } catch (QueryException) {
            $event=PaymentWebhookEvent::query()->where('provider',$provider)->where('provider_event_id',$verified->eventId)->firstOrFail();
        }

        if (! $event->wasRecentlyCreated && ! hash_equals((string) $event->payload_sha256, hash('sha256', $rawPayload))) {
            throw new \App\Domain\Payments\Exceptions\PaymentException('Payment webhook replay payload mismatch.');
        }

        if (! $event->wasRecentlyCreated && in_array($event->status,[PaymentWebhookStatus::Processed,PaymentWebhookStatus::NeedsReview,PaymentWebhookStatus::Duplicate],true)) {
            $event->increment('duplicate_count');
            $event->forceFill(['last_duplicate_at'=>now()])->save();
            return $event->fresh()->load('paymentIntent.order');
        }

        try {
            return DB::transaction(/** Inline callback for this operation. */ function () use ($event,$verified,$provider): PaymentWebhookEvent {
                $event=PaymentWebhookEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
                if(in_array($event->status,[PaymentWebhookStatus::Processed,PaymentWebhookStatus::NeedsReview,PaymentWebhookStatus::Duplicate],true)) return $event;

                $candidate=PaymentIntent::query()->where('public_id',$verified->paymentIntentPublicId)->first();
                if(!$candidate || $candidate->provider!==$provider){
                    $event->update(['status'=>PaymentWebhookStatus::NeedsReview,'processing_error'=>'Payment intent was not found for this provider.','processed_at'=>now()]);
                    return $event;
                }

                $session=null;
                if (($candidate->purpose ?? 'checkout') === 'checkout') {
                    if (! $candidate->checkout_session_id) {
                        $event->update(['status'=>PaymentWebhookStatus::NeedsReview,'processing_error'=>'Checkout payment intent has no checkout session.','processed_at'=>now()]);
                        return $event;
                    }
                    // Checkout/session lock first remains aligned with release/order paths.
                    $session=CheckoutSession::query()->whereKey($candidate->checkout_session_id)->lockForUpdate()->firstOrFail();
                }
                $intent=PaymentIntent::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                $event->update(['payment_intent_id'=>$intent->id]);
                $intent->forceFill(['provider_status'=>$verified->eventType,'provider_synced_at'=>now(),'provider_sync_error'=>null])->save();

                if (($verified->providerPaymentId && $intent->provider_payment_id && $verified->providerPaymentId!==$intent->provider_payment_id)
                    || $verified->currency!==$intent->currency || $verified->amountMinor!==$intent->amount_minor) {
                    [$type,$status,$amount]=match($verified->eventType){
                        'payment.paid'=>[PaymentTransactionType::Capture,PaymentTransactionStatus::Succeeded,$verified->amountMinor],
                        'payment.authorized'=>[PaymentTransactionType::Authorization,PaymentTransactionStatus::Succeeded,$verified->amountMinor],
                        default=>[PaymentTransactionType::Failure,PaymentTransactionStatus::Failed,0],
                    };
                    $this->recordTransaction($intent,$verified,$type,$status,$amount,['review_reason'=>'provider_reference_amount_or_currency_mismatch']);
                    $this->riskEvents->record($intent->user()->first(),null,'payment_webhook_mismatch','critical',30,'payments','payment_intent',$intent->public_id,'risk-payment-mismatch:'.$event->provider.':'.$event->provider_event_id,['eventType'=>$verified->eventType,'provider'=>$provider]);
                    $update=['status'=>PaymentIntentStatus::NeedsReview];
                    if($verified->eventType==='payment.paid')$update['paid_at']=now();
                    if($verified->eventType==='payment.authorized')$update['authorized_at']=now();
                    $intent->update($update);
                    $this->markCoinPurchase($intent,CoinPurchaseStatus::NeedsReview);
                    $event->update(['status'=>PaymentWebhookStatus::NeedsReview,'processing_error'=>'Provider payment details do not match the server payment intent.','processed_at'=>now()]);
                    return $event->fresh()->load('paymentIntent.order');
                }

                match($verified->eventType){
                    'payment.paid'=>$this->handlePaid($intent,$session,$verified,$event),
                    'payment.authorized'=>$this->handleAuthorized($intent,$verified,$event),
                    'payment.failed'=>$this->handleFailed($intent,$verified,$event),
                    default=>$event->update(['status'=>PaymentWebhookStatus::Processed,'processing_error'=>'Ignored unsupported event type.','processed_at'=>now()]),
                };
                return $event->fresh()->load('paymentIntent.order');
            },3);
        } catch(Throwable $exception){
            PaymentWebhookEvent::query()->whereKey($event->id)->update(['status'=>PaymentWebhookStatus::Failed->value,'processing_error'=>$exception->getMessage(),'processed_at'=>now()]);
            throw $exception;
        }
    }

    /** Handles handle paid for the process payment webhook workflow. */
    private function handlePaid(PaymentIntent $intent, ?CheckoutSession $session, VerifiedWebhook $verified, PaymentWebhookEvent $event): void
    {
        if($intent->status===PaymentIntentStatus::Paid && (($intent->purpose??'checkout')!=='checkout' || $intent->order_id)){
            $event->update(['status'=>PaymentWebhookStatus::Processed,'processed_at'=>now()]); return;
        }
        $this->recordTransaction($intent,$verified,PaymentTransactionType::Capture,PaymentTransactionStatus::Succeeded,$verified->amountMinor);
        $intent->update(['status'=>PaymentIntentStatus::Paid,'paid_at'=>now()]);
        if($intent->saved_payment_method_id){$intent->savedPaymentMethod()->where('status','active')->update(['last_used_at'=>now()]);}

        if (($intent->purpose ?? 'checkout') === 'coin_purchase') {
            try { $this->settleCoinPurchase->execute($intent); }
            catch(Throwable $e){
                $intent->update(['status'=>PaymentIntentStatus::NeedsReview,'metadata'=>array_merge($intent->metadata??[],['review_reason'=>'coin_purchase_credit_failed'])]);
                $this->markCoinPurchase($intent,CoinPurchaseStatus::NeedsReview);
                $event->update(['status'=>PaymentWebhookStatus::NeedsReview,'processing_error'=>'Payment captured but wallet credit failed: '.$e->getMessage(),'processed_at'=>now()]); return;
            }
            $event->update(['status'=>PaymentWebhookStatus::Processed,'processed_at'=>now()]); return;
        }

        if(!$session || $session->status!==CheckoutStatus::Reserved || $session->expires_at->isPast()){
            $intent->update(['status'=>PaymentIntentStatus::NeedsReview,'metadata'=>array_merge($intent->metadata??[],['review_reason'=>'paid_after_checkout_expiry_or_release'])]);
            $this->riskEvents->record($intent->user()->first(),null,'payment_after_checkout_expiry','high',15,'payments','payment_intent',$intent->public_id,'risk-payment-late:'.$event->provider.':'.$event->provider_event_id);
            $event->update(['status'=>PaymentWebhookStatus::NeedsReview,'processing_error'=>'Payment succeeded after inventory reservation was no longer fulfillable.','processed_at'=>now()]); return;
        }
        try { $order=$this->placeOrder->execute($intent->user()->firstOrFail(),$session,PaymentStatus::Paid); }
        catch(Throwable $e){
            $intent->update(['status'=>PaymentIntentStatus::NeedsReview,'metadata'=>array_merge($intent->metadata??[],['review_reason'=>'paid_but_order_fulfillment_failed'])]);
            $event->update(['status'=>PaymentWebhookStatus::NeedsReview,'processing_error'=>'Payment captured but order fulfillment failed: '.$e->getMessage(),'processed_at'=>now()]); return;
        }
        $intent->update(['order_id'=>$order->id]);
        PaymentTransaction::query()->where('payment_intent_id',$intent->id)->whereNull('order_id')->update(['order_id'=>$order->id]);
        $event->update(['status'=>PaymentWebhookStatus::Processed,'processed_at'=>now()]);
    }

    /** Handles handle authorized for the process payment webhook workflow. */
    private function handleAuthorized(PaymentIntent $intent, VerifiedWebhook $verified, PaymentWebhookEvent $event): void
    {
        $this->recordTransaction($intent,$verified,PaymentTransactionType::Authorization,PaymentTransactionStatus::Succeeded,$verified->amountMinor);
        $intent->update(['status'=>PaymentIntentStatus::Authorized,'authorized_at'=>now()]);
        $event->update(['status'=>PaymentWebhookStatus::Processed,'processed_at'=>now()]);
    }

    /** Handles handle failed for the process payment webhook workflow. */
    private function handleFailed(PaymentIntent $intent, VerifiedWebhook $verified, PaymentWebhookEvent $event): void
    {
        $this->recordTransaction($intent,$verified,PaymentTransactionType::Failure,PaymentTransactionStatus::Failed,0);
        $this->riskEvents->record($intent->user()->first(),null,'payment_failed','medium',5,'payments','payment_intent',$intent->public_id,'risk-payment-failed:'.$event->provider.':'.$event->provider_event_id);
        if($intent->status!==PaymentIntentStatus::Paid){ $intent->update(['status'=>PaymentIntentStatus::Failed,'failed_at'=>now()]); $this->markCoinPurchase($intent,CoinPurchaseStatus::Failed); }
        $event->update(['status'=>PaymentWebhookStatus::Processed,'processed_at'=>now()]);
    }

    /** Handles mark coin purchase for the process payment webhook workflow. */
    private function markCoinPurchase(PaymentIntent $intent, CoinPurchaseStatus $status): void
    {
        if(($intent->purpose??'checkout')==='coin_purchase') CoinPurchase::query()->where('payment_intent_id',$intent->id)->update(['status'=>$status->value]);
    }

    /** Handles record transaction for the process payment webhook workflow. */
    private function recordTransaction(PaymentIntent $intent, VerifiedWebhook $verified, PaymentTransactionType $type, PaymentTransactionStatus $status, int $amountMinor, array $metadata=[]): PaymentTransaction
    {
        return PaymentTransaction::query()->firstOrCreate(
            ['idempotency_key'=>"{$intent->provider}:{$verified->eventId}:{$type->value}"],
            ['public_id'=>(string)Str::ulid(),'payment_intent_id'=>$intent->id,'order_id'=>$intent->order_id,'provider'=>$intent->provider,'type'=>$type,'status'=>$status,
             'currency'=>$verified->currency,'amount_minor'=>$amountMinor,'provider_transaction_id'=>$verified->providerTransactionId,'metadata'=>$metadata,'occurred_at'=>$verified->occurredAt],
        );
    }
}
