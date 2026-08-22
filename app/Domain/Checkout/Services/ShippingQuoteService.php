<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Checkout\Exceptions\CheckoutValidationException;
use App\Models\Address;
use App\Models\Cart;

/** Defines the ShippingQuoteService class and its project responsibilities. */
class ShippingQuoteService
{
    /** Handles quotes for the shipping quote service workflow. */
    public function quotes(Cart $cart, Address $address): array
    {
        $vendorCount = max(1, $cart->items->map(/** Inline callback for this operation. */ fn ($item) => $item->product?->vendor_id ?? 0)->unique()->count());
        $methods = config('vsn.shipping_methods', []);

        return collect($methods)
            ->filter(/** Inline callback for this operation. */ fn (array $method) => (bool) ($method['enabled'] ?? true))
            ->map(/** Inline callback for this operation. */ function (array $method, string $code) use ($vendorCount, $address): array {
                $perVendor = (int) ($method['per_vendor_minor'] ?? 0);

                return [
                    'code' => $code,
                    'name' => (string) ($method['name'] ?? ucfirst($code)),
                    'eta' => (string) ($method['eta'] ?? ''),
                    'amountMinor' => $perVendor * $vendorCount,
                    'perVendorMinor' => $perVendor,
                    'vendorCount' => $vendorCount,
                    'currency' => config('vsn.currency', 'PKR'),
                    'countryCode' => $address->country_code,
                    'provider' => (string) ($method['provider'] ?? 'sandbox'),
                    'dispatchSlaHours' => (int) ($method['dispatch_sla_hours'] ?? 24),
                    'deliverySlaHours' => (int) ($method['delivery_sla_hours'] ?? 120),
                ];
            })
            ->values()
            ->all();
    }

    /** Handles resolve for the shipping quote service workflow. */
    public function resolve(Cart $cart, Address $address, string $code): array
    {
        $quote = collect($this->quotes($cart, $address))->firstWhere('code', $code);

        if (! $quote) {
            throw new CheckoutValidationException('The selected shipping method is unavailable.', 'shippingMethod');
        }

        return $quote;
    }
}
