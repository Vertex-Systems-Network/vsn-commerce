<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Defines the RequestContext class and its project responsibilities. */
class RequestContext
{
    /** Executes the request context operation. */
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get('X-Request-ID', '');
        $requestId = preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $incoming) ? $incoming : (string) Str::uuid();
        $request->attributes->set('request_id', $requestId);
        Log::withContext(['request_id'=>$requestId]);
        $start = hrtime(true);
        try { $response = $next($request); }
        finally {
            $duration = round((hrtime(true)-$start)/1_000_000, 2);
            if (! str_contains($request->path(), 'health')) {
                Log::info('http.request', [
                    'method'=>$request->method(), 'path'=>$request->path(),
                    'route'=>$request->route()?->getName() ?: $request->route()?->uri(),
                    'status'=>isset($response) ? $response->getStatusCode() : 500,
                    'duration_ms'=>$duration,
                    'user_id'=>$request->user()?->id,
                    'ip_hash'=>$request->ip() ? hash('sha256', (string)$request->ip()) : null,
                ]);
            }
        }
        $response->headers->set('X-Request-ID', $requestId);
        return $response;
    }
}
