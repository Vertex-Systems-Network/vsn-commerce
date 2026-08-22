<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Security\Rbac;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/** Defines the EnforceAreaRole class and its project responsibilities. */
class EnforceAreaRole
{
    /** Executes the enforce area role operation. */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) return $next($request);

        $role = $user->role instanceof UserRole ? $user->role->value : (string) $user->role;
        $path = $request->path();
        $token = $user->currentAccessToken();
        $isCustomerMobileToken = $token instanceof PersonalAccessToken && $token->can('mobile:access');

        if ($isCustomerMobileToken && (str_starts_with($path, 'api/v1/vendor/') || str_starts_with($path, 'api/v1/admin/'))) {
            abort(403, 'Privileged operational APIs are not available to customer Android access tokens.');
        }

        if (str_starts_with($path, 'api/v1/vendor/')) {
            abort_unless($role === UserRole::Seller->value, 403, 'Seller owner access is required.');
        }

        if (str_starts_with($path, 'api/v1/admin/')) {
            abort_unless(in_array($role, [UserRole::Support->value,UserRole::Finance->value,UserRole::Moderator->value,UserRole::Admin->value,UserRole::SuperAdmin->value], true), 403, 'Administrative access is required.');
        }

        Rbac::enforceAreaRequest($request);
        return $next($request);
    }
}
