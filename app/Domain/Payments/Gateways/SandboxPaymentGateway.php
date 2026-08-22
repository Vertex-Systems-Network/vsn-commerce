<?php

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Data\GatewayIntentResult;
use App\Domain\Payments\Data\GatewayRefundResult;
use App\Domain\Payments\Data\VerifiedWebhook;
use App\Domain\Payments\Exceptions\InvalidWebhookSignature;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Models\PaymentIntent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use JsonException;

/** Defines the SandboxPaymentGateway class and its project responsibilities. */
class SandboxPaymentGateway implements PaymentGateway
{
    /** Initializes the SandboxPaymentGateway instance and its dependencies. */
    public function __construct(private readonly string $webhookSecret) {}

    /** Handles code for the sandbox payment gateway workflow. */
    public function code(): string { return 'sandbox'; }

    /** Handles create intent for the sandbox payment gateway workflow. */
    public function createIntent(PaymentIntent $intent): GatewayIntentResult
    {
        return new GatewayIntentResult(
            providerPaymentId: 'sbx_pi_'.Str::lower((string) Str::ulid()),
            clientAction: $intent->savedPaymentMethod ? [
                'type' => 'saved_payment_method',
                'message' => 'Development sandbox will charge the selected vaulted test token.',
                'savedPaymentMethod' => ['brand'=>$intent->savedPaymentMethod->brand,'last4'=>$intent->savedPaymentMethod->last4],
            ] : [
                'type' => 'sandbox',
                'message' => 'Development sandbox only. No real card data is collected.',
            ],
            metadata: ['sandbox' => true],
        );
    }


    /** Handles refund for the sandbox payment gateway workflow. */
    public function refund(PaymentIntent $intent, int $amountMinor, string $idempotencyKey): GatewayRefundResult
    {
        if ($amountMinor <= 0 || $amountMinor > $intent->amount_minor) {
            throw new PaymentException('Invalid refund amount.');
        }
        return new GatewayRefundResult(
            providerTransactionId: 'sbx_rf_'.substr(hash('sha256', $idempotencyKey), 0, 24),
            status: 'succeeded',
            metadata: ['sandbox' => true],
        );
    }

    /** Handles verify webhook for the sandbox payment gateway workflow. */
    public function verifyWebhook(string $rawPayload, array $headers): VerifiedWebhook
    {
        $provided = $this->header($headers, 'x-vsn-signature');
        $expected = hash_hmac('sha256', $rawPayload, $this->webhookSecret);
        if (! is_string($provided) || $provided === '' || ! hash_equals($expected, $provided)) {
            throw new InvalidWebhookSignature('Invalid payment webhook signature.');
        }

        try {
            $payload = json_decode($rawPayload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PaymentException('Payment webhook payload is not valid JSON.');
        }

        foreach (['event_id', 'type', 'payment_intent_id', 'amount_minor', 'currency'] as $required) {
            if (! array_key_exists($required, $payload)) {
                throw new PaymentException("Payment webhook is missing {$required}.");
            }
        }

        return new VerifiedWebhook(
            eventId: (string) $payload['event_id'],
            eventType: (string) $payload['type'],
            paymentIntentPublicId: (string) $payload['payment_intent_id'],
            providerPaymentId: isset($payload['provider_payment_id']) ? (string) $payload['provider_payment_id'] : null,
            providerTransactionId: isset($payload['provider_transaction_id']) ? (string) $payload['provider_transaction_id'] : null,
            amountMinor: (int) $payload['amount_minor'],
            currency: strtoupper((string) $payload['currency']),
            occurredAt: CarbonImmutable::parse($payload['occurred_at'] ?? now()),
            payload: $payload,
        );
    }

    /** Handles signed event for the sandbox payment gateway workflow. */
    public function signedEvent(PaymentIntent $intent, string $eventType = 'payment.paid'): array
    {
        $payload = [
            'event_id' => 'sbx_evt_'.Str::lower((string) Str::ulid()),
            'type' => $eventType,
            'payment_intent_id' => $intent->public_id,
            'provider_payment_id' => $intent->provider_payment_id,
            'provider_transaction_id' => 'sbx_txn_'.Str::lower((string) Str::ulid()),
            'amount_minor' => $intent->amount_minor,
            'currency' => $intent->currency,
            'occurred_at' => now()->toIso8601String(),
        ];
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [
            'raw' => $raw,
            'headers' => ['x-vsn-signature' => [hash_hmac('sha256', $raw, $this->webhookSecret)]],
        ];
    }

    /** Handles lookup intent for the sandbox payment gateway workflow. */
    public function lookupIntent(PaymentIntent $intent): array
    {
        return [
            'providerPaymentId' => $intent->provider_payment_id,
            'status' => match ($intent->status->value) {
                'paid' => 'succeeded', 'failed' => 'failed', 'cancelled' => 'canceled', 'authorized' => 'requires_capture', default => 'requires_action',
            },
            'amountMinor' => $intent->amount_minor,
            'currency' => $intent->currency,
            'raw' => ['sandbox' => true],
        ];
    }

    /** Handles header for the sandbox payment gateway workflow. */
    private function header(array $headers, string $name): ?string
    {
        $value = $headers[strtolower($name)] ?? $headers[$name] ?? null;
        if (is_array($value)) return isset($value[0]) ? (string) $value[0] : null;
        return is_string($value) ? $value : null;
    }
}
