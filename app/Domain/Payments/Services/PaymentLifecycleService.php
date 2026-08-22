<?php

namespace App\Domain\Payments\Services;

use App\Domain\Checkout\Exceptions\CheckoutValidationException;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Enums\CheckoutStatus;
use App\Enums\PaymentIntentStatus;
use App\Models\PaymentIntent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Defines the PaymentLifecycleService class and its project responsibilities. */
final class PaymentLifecycleService
{
    /** Initializes the PaymentLifecycleService instance and its dependencies. */
    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    /** Handles retry initialization for the payment lifecycle service workflow. */
    public function retryInitialization(User $user, PaymentIntent $paymentIntent): PaymentIntent
    {
        $intent = DB::transaction(/** Inline callback for this operation. */ function () use ($user, $paymentIntent): PaymentIntent {
            $intent = PaymentIntent::query()->whereKey($paymentIntent->id)->lockForUpdate()->with(['checkoutSession','savedPaymentMethod'])->firstOrFail();
            abort_unless($intent->user_id === $user->id, 404);
            if ($intent->status !== PaymentIntentStatus::Failed || $intent->provider_payment_id) {
                throw new PaymentException('Only a failed provider initialization without a provider payment reference can be retried.');
            }
            $session = $intent->checkoutSession;
            if (($intent->purpose ?? 'checkout') === 'checkout') {
                if (! $session || $session->status !== CheckoutStatus::Reserved || $session->expires_at->isPast()) {
                    throw new CheckoutValidationException('The checkout reservation is no longer active.');
                }
            }
            $max = max(1, (int) config('vsn.payments.max_initialization_attempts', 3));
            if ($intent->initialization_attempts >= $max) throw new PaymentException('Payment initialization retry limit reached.');
            $intent->forceFill([
                'status' => PaymentIntentStatus::Creating,
                'failed_at' => null,
                'provider_sync_error' => null,
                'initialization_attempts' => $intent->initialization_attempts + 1,
                'last_initialization_attempt_at' => now(),
            ])->save();
            return $intent;
        }, 3);

        try {
            $result = $this->gateways->gateway($intent->provider)->createIntent($intent);
            $intent->forceFill([
                'provider_payment_id' => $result->providerPaymentId,
                'client_action' => $result->clientAction,
                'status' => PaymentIntentStatus::RequiresAction,
                'provider_status' => (string) ($result->metadata['stripe_status'] ?? $result->clientAction['status'] ?? 'requires_action'),
                'provider_synced_at' => now(),
                'provider_sync_error' => null,
                'metadata' => array_merge($intent->metadata ?? [], $result->metadata, ['initialization_retried' => true]),
            ])->save();
        } catch (Throwable $e) {
            $intent->forceFill([
                'status' => PaymentIntentStatus::Failed,
                'failed_at' => now(),
                'provider_sync_error' => $e->getMessage(),
                'metadata' => array_merge($intent->metadata ?? [], ['provider_error' => $e->getMessage()]),
            ])->save();
            throw $e instanceof PaymentException ? $e : new PaymentException('Payment provider initialization retry failed.');
        }
        return $intent->fresh()->load(['order','savedPaymentMethod']);
    }

    /** Handles sync for the payment lifecycle service workflow. */
    public function sync(PaymentIntent $paymentIntent): PaymentIntent
    {
        $intent = PaymentIntent::query()->whereKey($paymentIntent->id)->with(['order','savedPaymentMethod'])->firstOrFail();
        if (! $intent->provider_payment_id) return $intent;
        try {
            $remote = $this->gateways->gateway($intent->provider)->lookupIntent($intent);
            $status = (string) ($remote['status'] ?? 'unknown');
            $amount = (int) ($remote['amountMinor'] ?? -1);
            $currency = strtoupper((string) ($remote['currency'] ?? ''));
            $mismatch = ($amount >= 0 && $amount !== $intent->amount_minor) || ($currency !== '' && $currency !== $intent->currency);
            $meta = $intent->metadata ?? [];
            if ($mismatch) $meta['review_reason'] = 'provider_amount_or_currency_mismatch';
            $remotePaidAwaitingWebhook = in_array($status, ['succeeded','paid'], true) && $intent->status !== PaymentIntentStatus::Paid;
            if ($remotePaidAwaitingWebhook) {
                $meta['review_reason'] = 'provider_paid_awaiting_signed_webhook';
            }
            $intent->forceFill([
                'provider_status' => $status,
                'provider_synced_at' => now(),
                'provider_sync_error' => null,
                'metadata' => $meta,
                'status' => ($mismatch || $remotePaidAwaitingWebhook) ? PaymentIntentStatus::NeedsReview : $intent->status,
            ])->save();
        } catch (Throwable $e) {
            $intent->forceFill(['provider_synced_at' => now(), 'provider_sync_error' => $e->getMessage()])->save();
            throw $e instanceof PaymentException ? $e : new PaymentException('Payment provider status refresh failed.');
        }
        return $intent->fresh()->load(['order','savedPaymentMethod']);
    }
}
