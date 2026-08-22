<?php

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Domain\Payments\Gateways\SandboxPaymentGateway;
use App\Domain\Payments\Gateways\StripePaymentGateway;

/** Defines the PaymentGatewayManager class and its project responsibilities. */
class PaymentGatewayManager
{
    /** Handles provider for method for the payment gateway manager workflow. */
    public function providerForMethod(string $method): string
    {
        $provider = config("vsn.payments.methods.{$method}.provider");
        if (! is_string($provider) || $provider === '') {
            throw new PaymentException('No payment provider is configured for this method.', 'paymentMethod');
        }
        return $provider;
    }

    /** Handles method enabled for the payment gateway manager workflow. */
    public function methodEnabled(string $method): bool
    {
        if (! (bool) config("vsn.payments.methods.{$method}.enabled", false)) {
            return false;
        }

        $provider = config("vsn.payments.methods.{$method}.provider");
        if ($provider === 'sandbox' && app()->isProduction()) {
            return false;
        }

        return true;
    }

    /** Handles gateway for the payment gateway manager workflow. */
    public function gateway(string $provider): PaymentGateway
    {
        if ($provider === 'sandbox' && app()->isProduction()) {
            throw new PaymentException('The sandbox payment provider is disabled in production.', 'paymentMethod');
        }

        return match ($provider) {
            'sandbox' => new SandboxPaymentGateway((string) config('vsn.payments.providers.sandbox.webhook_secret')),
            'stripe' => new StripePaymentGateway(
                (string) config('vsn.payments.providers.stripe.secret_key'),
                (string) config('vsn.payments.providers.stripe.publishable_key'),
                (string) config('vsn.payments.providers.stripe.webhook_secret'),
                (string) config('vsn.payments.providers.stripe.api_base', 'https://api.stripe.com'),
                (int) config('vsn.payments.providers.stripe.webhook_tolerance_seconds', 300),
            ),
            default => throw new PaymentException("Payment provider [{$provider}] is not registered.", 'paymentMethod'),
        };
    }

    /** Handles methods for the payment gateway manager workflow. */
    public function methods(): array
    {
        return collect(config('vsn.payments.methods', []))
            ->map(/** Inline callback for this operation. */ fn (array $method, string $code) => [
                'code' => $code,
                'name' => (string) ($method['name'] ?? ucfirst($code)),
                'description' => (string) ($method['description'] ?? ''),
                'enabled' => $this->methodEnabled($code),
                'provider' => $method['provider'] ?? null,
            ])->values()->all();
    }
}
