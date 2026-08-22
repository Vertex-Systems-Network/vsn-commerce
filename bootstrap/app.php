<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(/** Inline callback for this operation. */ function (Middleware $middleware): void {
        $proxies = array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))));
        if ($proxies !== []) {
            $middleware->trustProxies(at: $proxies, headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR | \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST | \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO);
        }
        $middleware->statefulApi();
        $middleware->appendToGroup('web', \App\Http\Middleware\WebSecurityHeaders::class);
        $middleware->prependToGroup('api', \App\Http\Middleware\RequestPerformanceTelemetry::class);
        $middleware->prependToGroup('api', \App\Http\Middleware\LimitRequestBody::class);
        $middleware->prependToGroup('api', \App\Http\Middleware\RequestContext::class);
        $middleware->appendToGroup('api', \App\Http\Middleware\ApiSecurityHeaders::class);
        $middleware->appendToGroup('api', \App\Http\Middleware\AuditAdminMutation::class);
        $middleware->appendToGroup('api', \App\Http\Middleware\MobileClientContext::class);
        $middleware->alias([
            'mobile.access' => \App\Http\Middleware\RequireMobileAccessToken::class,
            'area.role' => \App\Http\Middleware\EnforceAreaRole::class,
        ]);
    })
    ->withExceptions(/** Inline callback for this operation. */ function (Exceptions $exceptions): void {
        $isMobile = static /** Inline callback for this operation. */ fn (\Illuminate\Http\Request $request): bool =>
            $request->is('api/mobile/*') || strtolower((string) $request->header('X-VSN-Client')) === 'android';

        $exceptions->shouldRenderJsonWhen(/** Inline callback for this operation. */ fn (\Illuminate\Http\Request $request, \Throwable $e): bool =>
            $isMobile($request) || $request->expectsJson()
        );

        $exceptions->render(/** Inline callback for this operation. */ function (\Illuminate\Validation\ValidationException $e, \Illuminate\Http\Request $request) use ($isMobile) {
            if (! $isMobile($request)) return null;
            return response()->json(['error' => [
                'code' => 'validation_error',
                'message' => 'Please check the submitted fields.',
                'fields' => $e->errors(),
                'requestId' => $request->attributes->get('request_id'),
            ]], 422);
        });

        $exceptions->render(/** Inline callback for this operation. */ function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) use ($isMobile) {
            if (! $isMobile($request)) return null;
            return response()->json(['error' => [
                'code' => 'unauthenticated', 'message' => 'Authentication is required.',
                'requestId' => $request->attributes->get('request_id'),
            ]], 401);
        });

        $exceptions->render(/** Inline callback for this operation. */ function (\Illuminate\Auth\Access\AuthorizationException $e, \Illuminate\Http\Request $request) use ($isMobile) {
            if (! $isMobile($request)) return null;
            return response()->json(['error' => [
                'code' => 'forbidden', 'message' => $e->getMessage() ?: 'This action is not allowed.',
                'requestId' => $request->attributes->get('request_id'),
            ]], 403);
        });

        $exceptions->render(/** Inline callback for this operation. */ function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, \Illuminate\Http\Request $request) use ($isMobile) {
            if (! $isMobile($request)) return null;
            return response()->json(['error' => [
                'code' => 'not_found', 'message' => 'The requested resource was not found.',
                'requestId' => $request->attributes->get('request_id'),
            ]], 404);
        });

        $exceptions->render(/** Inline callback for this operation. */ function (\Illuminate\Http\Exceptions\ThrottleRequestsException $e, \Illuminate\Http\Request $request) use ($isMobile) {
            if (! $isMobile($request)) return null;
            return response()->json(['error' => [
                'code' => 'rate_limited', 'message' => 'Too many requests. Please retry later.',
                'requestId' => $request->attributes->get('request_id'),
            ]], 429, $e->getHeaders());
        });
    })->create();
