<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Checkout\Exceptions\CheckoutValidationException;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Domain\Payments\Services\PaymentGatewayManager;
use App\Domain\Risk\Services\RiskGate;
use App\Domain\Risk\Services\RiskRecorder;
use App\Domain\Risk\Exceptions\RiskBlockedException;
use App\Enums\CheckoutStatus;
use App\Enums\PaymentIntentStatus;
use App\Models\CheckoutSession;
use App\Models\PaymentIntent;
use App\Models\SavedPaymentMethod;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Defines the CreatePaymentIntent class and its project responsibilities. */
class CreatePaymentIntent
{
    /** Initializes the CreatePaymentIntent instance and its dependencies. */
    public function __construct(private readonly PaymentGatewayManager $gateways, private readonly RiskGate $risk, private readonly RiskRecorder $riskEvents) {}

    /** Executes the create payment intent operation. */
    public function execute(User $user, CheckoutSession $session, string $idempotencyKey): PaymentIntent
    {
        $existing = PaymentIntent::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            abort_unless($existing->user_id === $user->id && $existing->checkout_session_id === $session->id, 409);
            return $existing->load(['order','savedPaymentMethod']);
        }
        try { $this->risk->payment($user); }
        catch (RiskBlockedException $e) { throw new PaymentException($e->getMessage(), 'risk'); }

        if ($session->payment_method === 'cod') {
            throw new PaymentException('Cash on delivery does not require a payment intent.', 'paymentMethod');
        }
        if (! $this->gateways->methodEnabled($session->payment_method)) {
            throw new PaymentException('The selected payment method is disabled.', 'paymentMethod');
        }
        $provider = $this->gateways->providerForMethod($session->payment_method);

        try {
            $intent = DB::transaction(/** Inline callback for this operation. */ function () use ($user, $session, $idempotencyKey, $provider): PaymentIntent {
                $session = CheckoutSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
                abort_unless($session->user_id === $user->id, 404);

                $existing = PaymentIntent::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) return $existing;

                if ($session->status !== CheckoutStatus::Reserved) {
                    throw new CheckoutValidationException('This checkout session is no longer active.');
                }
                if ($session->expires_at->isPast()) {
                    throw new CheckoutValidationException('The checkout reservation has expired.');
                }

                if ($session->saved_payment_method_id) {
                    $saved = SavedPaymentMethod::query()->whereKey($session->saved_payment_method_id)->where('user_id',$user->id)->lockForUpdate()->first();
                    if (! $saved || ! $saved->isActive() || $saved->provider !== $provider) {
                        throw new PaymentException('The selected saved payment method is no longer available.', 'savedPaymentMethodId');
                    }
                }

                $paid = PaymentIntent::query()
                    ->where('checkout_session_id', $session->id)
                    ->where('status', PaymentIntentStatus::Paid->value)
                    ->first();
                if ($paid) return $paid;

                return PaymentIntent::create([
                    'public_id' => (string) Str::ulid(),
                    'user_id' => $user->id,
                    'checkout_session_id' => $session->id,
                    'idempotency_key' => $idempotencyKey,
                    'purpose' => 'checkout',
                    'reference_type' => 'checkout_session',
                    'reference_id' => $session->public_id,
                    'provider' => $provider,
                    'payment_method' => $session->payment_method,
                    'saved_payment_method_id' => $session->saved_payment_method_id,
                    'status' => PaymentIntentStatus::Creating,
                    'currency' => $session->currency,
                    'amount_minor' => $session->total_minor,
                    'expires_at' => $session->expires_at,
                    'metadata' => [
                        'checkout_public_id' => $session->public_id,
                        'saved_payment_method_public_id' => $session->savedPaymentMethod?->public_id,
                    ],
                ]);
            }, 3);
        } catch (QueryException $exception) {
            $intent = PaymentIntent::query()->where('idempotency_key', $idempotencyKey)->first();
            if (! $intent) throw $exception;
        }

        if ($intent->status !== PaymentIntentStatus::Creating) {
            return $intent->load(['order','savedPaymentMethod']);
        }

        try {
            $intent->loadMissing('savedPaymentMethod');
            $intent->forceFill([
                'initialization_attempts' => $intent->initialization_attempts + 1,
                'last_initialization_attempt_at' => now(),
                'provider_sync_error' => null,
            ])->save();
            $result = $this->gateways->gateway($provider)->createIntent($intent);
            $intent->update([
                'provider_payment_id' => $result->providerPaymentId,
                'client_action' => $result->clientAction,
                'status' => PaymentIntentStatus::RequiresAction,
                'provider_status' => (string) ($result->metadata['stripe_status'] ?? $result->clientAction['status'] ?? 'requires_action'),
                'provider_synced_at' => now(),
                'provider_sync_error' => null,
                'metadata' => array_merge($intent->metadata ?? [], $result->metadata),
            ]);
        } catch (Throwable $exception) {
            $this->riskEvents->record($user, null, 'payment_provider_initialization_failed', 'medium', 5, 'payments', 'payment_intent', $intent->public_id, 'risk-payment-init-failed:'.$intent->public_id, ['provider'=>$provider]);
            $intent->update([
                'status' => PaymentIntentStatus::Failed,
                'failed_at' => now(),
                'provider_synced_at' => now(),
                'provider_sync_error' => $exception->getMessage(),
                'metadata' => array_merge($intent->metadata ?? [], ['provider_error' => $exception->getMessage()]),
            ]);
            throw new PaymentException('Payment provider could not initialize the payment.');
        }

        return $intent->fresh()->load(['order','savedPaymentMethod']);
    }
}
