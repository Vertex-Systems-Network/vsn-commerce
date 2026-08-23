<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Verifies clean browser URLs and core same-origin API routes resolve through Laravel. */
class DirectUrlRoutingTest extends TestCase
{
    use RefreshDatabase;

    /** Ensure every public SPA entry URL typed directly into the browser returns the React shell. */
    public function test_all_direct_spa_entry_urls_return_the_react_shell(): void
    {
        $this->withoutVite();

        $urls = [
            '/', '/auth', '/login', '/register', '/auth/callback', '/forgot-password', '/reset-password', '/access-denied',
            '/search', '/deals', '/vendors', '/shop/demo-store', '/product/demo-product', '/games', '/help', '/legal', '/cart',
            '/dashboard', '/orders', '/profile', '/coins', '/affiliate', '/reviews', '/gifts', '/wallet', '/invoices',
            '/tracking', '/notifications', '/messages', '/settings', '/returns', '/saved-alerts', '/wishlist',
            '/recently-viewed', '/buy-again', '/checkout',
            '/account', '/account/profile', '/account/addresses', '/account/orders', '/account/orders/demo-order',
            '/account/wishlist', '/account/wallet', '/account/payment-methods', '/account/verification', '/account/security',
            '/account/notifications', '/account/messages', '/account/returns',
            '/vendor', '/vendor/products', '/vendor/media', '/vendor/products/new', '/vendor/products/demo-product/edit', '/vendor/inventory',
            '/vendor/orders', '/vendor/orders/demo-order', '/vendor/shipping', '/vendor/returns', '/vendor/promotions',
            '/vendor/reviews', '/vendor/finance', '/vendor/payouts', '/vendor/analytics', '/vendor/verification',
            '/vendor/tax', '/vendor/tax-invoices', '/vendor/settings',
            '/admin', '/admin/users', '/admin/access', '/admin/vendors', '/admin/catalog', '/admin/catalog/new',
            '/admin/catalog/demo-product/edit', '/admin/promotions', '/admin/loyalty', '/admin/games', '/admin/tax',
            '/admin/reviews', '/admin/media', '/admin/compliance', '/admin/risk', '/admin/analytics', '/admin/orders',
            '/admin/orders/demo-order', '/admin/shipping', '/admin/payments', '/admin/returns', '/admin/returns/demo-return',
            '/admin/finance', '/admin/payouts', '/admin/notifications', '/admin/settings', '/admin/operations',
            '/admin/seller-quality', '/admin/production-readiness', '/admin/acceptance',
        ];

        foreach ($urls as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('id="root"', false);
        }
    }

    /** Ensure API and Sanctum URLs are routed to Laravel rather than Apache 404 pages. */
    public function test_core_api_and_sanctum_urls_are_not_web_server_404s(): void
    {
        $this->getJson('/api/v1/health')->assertSuccessful();
        $this->getJson('/api/v1/cart')->assertOk();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();

        $csrf = $this->get('/sanctum/csrf-cookie');
        $this->assertNotSame(404, $csrf->getStatusCode());
    }
}
