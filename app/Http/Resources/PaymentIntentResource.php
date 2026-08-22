<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Defines the PaymentIntentResource class and its project responsibilities. */
class PaymentIntentResource extends JsonResource
{
    /** Handles to array for the payment intent resource workflow. */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'purpose' => $this->purpose ?? 'checkout',
            'referenceType' => $this->reference_type,
            'referenceId' => $this->reference_id,
            'provider' => $this->provider,
            'paymentMethod' => $this->payment_method,
            'savedPaymentMethod' => $this->savedPaymentMethod ? (new SavedPaymentMethodResource($this->savedPaymentMethod))->resolve($request) : null,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'amountMinor' => $this->amount_minor,
            'providerPaymentId' => $this->provider_payment_id,
            'providerStatus' => $this->provider_status,
            'providerSyncedAt' => $this->provider_synced_at?->toISOString(),
            'providerSyncError' => $this->provider_sync_error,
            'initializationAttempts' => (int) $this->initialization_attempts,
            'canRetryInitialization' => $this->status->value === 'failed' && ! $this->provider_payment_id,
            'clientAction' => $this->client_action,
            'expiresAt' => $this->expires_at?->toISOString(),
            'orderId' => $this->order?->public_id,
            'sandboxCanSimulate' => $this->provider === 'sandbox'
                && (bool) config('vsn.payments.providers.sandbox.simulator_enabled')
                && ! app()->isProduction(),
        ];
    }
}
