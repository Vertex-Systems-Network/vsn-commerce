<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Defines the LimitRequestBody class and its project responsibilities. */
class LimitRequestBody
{
    /** Executes the limit request body operation. */
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            $bytes = (int) $request->server('CONTENT_LENGTH', 0);
            $limit = $this->limitFor($request);
            if ($bytes > 0 && $limit > 0 && $bytes > $limit) {
                return response()->json([
                    'message' => 'The request body is too large.',
                    'error' => [
                        'code' => 'request_too_large',
                        'maxBytes' => $limit,
                        'requestId' => $request->attributes->get('request_id'),
                    ],
                ], 413);
            }
        }
        return $next($request);
    }

    /** Handles limit for for the limit request body workflow. */
    private function limitFor(Request $request): int
    {
        $path = $request->path();
        $upload = str_contains($path, '/media') || str_contains($path, '/kyc') || str_contains($path, '/messages/conversations');
        return $upload
            ? (int) config('vsn.security.max_upload_request_bytes', 52_428_800)
            : (int) config('vsn.security.max_api_request_bytes', 2_097_152);
    }
}
