<?php
namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Defines the AuditAdminMutation class and its project responsibilities. */
class AuditAdminMutation
{
    /** Executes the audit admin mutation operation. */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $role = $user?->role;
        $roleValue = $role instanceof UserRole ? $role->value : (string) $role;
        $shouldAudit = $user
            && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
            && str_starts_with($request->path(), 'api/v1/admin/')
            && in_array($roleValue, [UserRole::Moderator->value, UserRole::Finance->value, UserRole::Admin->value, UserRole::SuperAdmin->value], true);

        if (! $shouldAudit) {
            return $next($request);
        }

        $route = $request->route();
        $target = $this->target($route?->parameters() ?? []);
        $safe = $request->except(['password', 'password_confirmation', 'code', 'document_number']);
        $correlationId = (string) Str::uuid();
        $base = [
            'actor_user_id' => $user->id,
            'method' => $request->method(),
            'path' => $request->path(),
            'target_type' => $target['type'] ?? null,
            'target_id' => $target['id'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_hash' => hash('sha256', (string) json_encode($safe, JSON_UNESCAPED_SLASHES)),
        ];

        // Fail closed before the business mutation: an administrative write must have an audit attempt record.
        AdminAuditLog::create($base + [
            'public_id' => (string) Str::uuid(),
            'action' => ($route?->getActionName() ?: $request->path()).':attempt',
            'response_status' => null,
            'metadata' => ['phase' => 'attempt', 'correlationId' => $correlationId, 'routeName' => $route?->getName()],
            'created_at' => now(),
        ]);

        $response = $next($request);

        // Result logging is best-effort; never convert a completed business mutation into a client-visible 500.
        try {
            AdminAuditLog::create($base + [
                'public_id' => (string) Str::uuid(),
                'action' => ($route?->getActionName() ?: $request->path()).':result',
                'response_status' => $response->getStatusCode(),
                'metadata' => ['phase' => 'result', 'correlationId' => $correlationId, 'routeName' => $route?->getName()],
                'created_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $response;
    }

    /** Handles target for the audit admin mutation workflow. */
    private function target(array $params): ?array
    {
        foreach ($params as $key => $value) {
            if (is_object($value) && isset($value->id)) return ['type' => class_basename($value), 'id' => (string) $value->id];
            if (is_scalar($value)) return ['type' => (string) $key, 'id' => (string) $value];
        }
        return null;
    }
}
