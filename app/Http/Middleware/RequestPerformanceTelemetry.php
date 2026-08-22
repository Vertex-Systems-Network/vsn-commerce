<?php

namespace App\Http\Middleware;

use App\Support\RequestPerformanceMetrics;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/** Defines the RequestPerformanceTelemetry class and its project responsibilities. */
class RequestPerformanceTelemetry
{
    /** Initializes the RequestPerformanceTelemetry instance and its dependencies. */
    public function __construct(private readonly RequestPerformanceMetrics $metrics) {}

    /** Executes the request performance telemetry operation. */
    public function handle(Request $request, Closure $next): Response
    {
        $start = hrtime(true);
        $baselineMemory = memory_get_usage(true);
        $response = $next($request);
        $durationMs = round((hrtime(true) - $start) / 1_000_000, 2);
        $memoryMb = round(max(0, memory_get_peak_usage(true) - $baselineMemory) / 1_048_576, 2);

        $queryCount = $this->metrics->queryCount();
        $queryMs = $this->metrics->queryMs();
        $duplicatePeak = $this->metrics->peakDuplicateCount();
        $exceeded = $durationMs > (int) config('vsn.performance.request_budget_ms', 1500)
            || $queryCount > (int) config('vsn.performance.query_budget', 80)
            || $duplicatePeak > (int) config('vsn.performance.duplicate_query_budget', 8)
            || $memoryMb > (int) config('vsn.performance.memory_budget_mb', 96);

        if ($exceeded && ! str_contains($request->path(), 'health')) {
            Log::warning('http.performance_budget_exceeded', [
                'route' => $request->route()?->uri(),
                'method' => $request->method(),
                'duration_ms' => $durationMs,
                'query_count' => $queryCount,
                'query_ms' => $queryMs,
                'peak_duplicate_query_count' => $duplicatePeak,
                'duplicate_query_fingerprints' => $this->metrics->duplicateFingerprints(),
                'memory_mb' => $memoryMb,
                'request_id' => $request->attributes->get('request_id'),
            ]);
        }

        if ((bool) config('vsn.performance.expose_server_timing', ! app()->isProduction())) {
            $response->headers->set('Server-Timing', "app;dur={$durationMs}, db;dur={$queryMs};desc=\"{$queryCount} queries\"");
        }
        return $response;
    }
}
