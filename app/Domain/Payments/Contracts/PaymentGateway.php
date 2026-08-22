<?php

namespace App\Domain\Payments\Contracts;

use App\Domain\Payments\Data\GatewayIntentResult;
use App\Domain\Payments\Data\GatewayRefundResult;
use App\Domain\Payments\Data\VerifiedWebhook;
use App\Models\PaymentIntent;

/** Defines the PaymentGateway interface and its project responsibilities. */
interface PaymentGateway
{
    /** Handles code for the payment gateway workflow. */
    public function code(): string;
    /** Handles create intent for the payment gateway workflow. */
    public function createIntent(PaymentIntent $intent): GatewayIntentResult;
    /** Handles refund for the payment gateway workflow. */
    public function refund(PaymentIntent $intent, int $amountMinor, string $idempotencyKey): GatewayRefundResult;
    /** Handles verify webhook for the payment gateway workflow. */
    public function verifyWebhook(string $rawPayload, array $headers): VerifiedWebhook;
    /** Handles lookup intent for the payment gateway workflow. */
    public function lookupIntent(PaymentIntent $intent): array;
}
