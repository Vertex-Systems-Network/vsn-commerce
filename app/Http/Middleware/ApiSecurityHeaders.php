<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Defines the ApiSecurityHeaders class and its project responsibilities. */
class ApiSecurityHeaders
{
    /** Executes the api security headers operation. */
    public function handle(Request $request, Closure $next): Response
    {
        $response=$next($request);
        $response->headers->set('X-Content-Type-Options','nosniff');
        $response->headers->set('X-Frame-Options','DENY');
        $response->headers->set('Referrer-Policy','no-referrer');
        $response->headers->set('Permissions-Policy','camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $response->headers->set('Cross-Origin-Opener-Policy','same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy','same-site');
        $response->headers->set('X-Permitted-Cross-Domain-Policies','none');
        if ($request->isSecure() || app()->isProduction()) $response->headers->set('Strict-Transport-Security','max-age=31536000; includeSubDomains');

        if ($this->isSensitive($request) || $request->user()) {
            $response->headers->set('Cache-Control','no-store, private');
            $response->headers->set('Pragma','no-cache');
        } elseif ($request->isMethod('GET') && $this->isPublicCacheable($request) && $response->isSuccessful()) {
            $response->headers->set('Cache-Control','public, max-age=30, stale-while-revalidate=60');
            $response->headers->set('Vary','Accept, Accept-Encoding');
        }
        return $response;
    }

    /** Handles is sensitive for the api security headers workflow. */
    private function isSensitive(Request $request): bool
    {
        foreach (['api/v1/auth/*','api/mobile/v1/auth/*','api/v1/cart*','api/v1/checkout/*','api/v1/payments/*','api/v1/profile*','api/v1/security*','api/v1/kyc*','api/v1/wallet*','api/v1/messages*','api/v1/notifications*','api/v1/admin/*','api/v1/vendor/*'] as $pattern) {
            if ($request->is($pattern)) return true;
        }
        return false;
    }

    /** Handles is public cacheable for the api security headers workflow. */
    private function isPublicCacheable(Request $request): bool
    {
        foreach (['api/v1/products','api/v1/products/*','api/v1/categories','api/v1/search/suggestions','api/v1/search/trending','api/v1/deals','api/v1/games','api/v1/games/*'] as $pattern) {
            if ($request->is($pattern)) return true;
        }
        return false;
    }
}
