<?php
namespace App\Domain\Shipping\Services;
use App\Domain\Shipping\Contracts\ShippingProvider;
use App\Domain\Shipping\Exceptions\ShippingException;
use App\Domain\Shipping\Providers\SandboxShippingProvider;
use App\Domain\Shipping\Providers\HttpShippingProvider;
/** Defines the ShippingProviderManager class and its project responsibilities. */
final class ShippingProviderManager
{
    /** Handles driver for the shipping provider manager workflow. */
    public function driver(string $code): ShippingProvider
    {
        return match ($code) {
            'sandbox' => app(SandboxShippingProvider::class),
            'courier_http' => new HttpShippingProvider('courier_http',(string)config('vsn.shipping.providers.courier_http.base_url'),(string)config('vsn.shipping.providers.courier_http.api_token'),(string)config('vsn.shipping.providers.courier_http.webhook_secret'),(string)config('vsn.shipping.providers.courier_http.create_path','/shipments'),(string)config('vsn.shipping.providers.courier_http.health_path','/health')),
            default => throw new ShippingException("Shipping provider [{$code}] is not configured."),
        };
    }
}
