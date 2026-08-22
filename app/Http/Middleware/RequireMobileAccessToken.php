<?php

namespace App\Http\Middleware;

use App\Models\MobileApiSession;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/** Defines the RequireMobileAccessToken class and its project responsibilities. */
class RequireMobileAccessToken
{
    /** Executes the require mobile access token operation. */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        if (! $token instanceof PersonalAccessToken || ! $token->can('mobile:access')) {
            return $this->deny($request, 'mobile_token_required', 'A valid Android access token is required.');
        }

        $session = $request->attributes->get('mobile_session');
        if (! $session instanceof MobileApiSession) {
            $session = MobileApiSession::query()
                ->where('access_token_id', $token->getKey())
                ->where('user_id', $request->user()->id)
                ->first();
        }

        if (! $session || ! $session->active()) {
            return $this->deny($request, 'mobile_session_revoked', 'This Android session is no longer active.');
        }

        $deviceId = trim((string) $request->header('X-Device-Id'));
        if ($deviceId === '' || ! hash_equals($session->device_key_hash, hash('sha256', $deviceId))) {
            return $this->deny($request, 'mobile_device_mismatch', 'This access token belongs to a different Android installation.');
        }

        $request->attributes->set('mobile_session', $session);
        return $next($request);
    }

    /** Handles deny for the require mobile access token workflow. */
    private function deny(Request $request, string $code, string $message): Response
    {
        return response()->json(['error' => [
            'code' => $code,
            'message' => $message,
            'requestId' => $request->attributes->get('request_id'),
        ]], 401);
    }
}
