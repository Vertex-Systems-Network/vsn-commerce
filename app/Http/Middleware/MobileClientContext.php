<?php

namespace App\Http\Middleware;

use App\Models\MobileApiSession;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/** Defines the MobileClientContext class and its project responsibilities. */
class MobileClientContext
{
    /** Executes the mobile client context operation. */
    public function handle(Request $request, Closure $next): Response
    {
        $presentedBearer = $request->bearerToken();
        $candidate = null;
        if ($presentedBearer) {
            $found = PersonalAccessToken::findToken((string) $presentedBearer);
            if ($found instanceof PersonalAccessToken && $found->can('mobile:access')) {
                $candidate = $found;
            }
        }

        $isMobilePath = $request->is('api/mobile/*');
        $isAndroid = $isMobilePath
            || strtolower((string) $request->header('X-VSN-Client')) === 'android'
            || $candidate instanceof PersonalAccessToken;

        if (! $isAndroid) {
            return $next($request);
        }
        if (strtolower((string) $request->header('X-VSN-Client')) !== 'android') {
            $request->headers->set('X-VSN-Client', 'android');
        }

        // A mobile request that explicitly presents a Bearer credential must never
        // fall back to a stateful browser session when that credential is invalid,
        // revoked, or lacks the mobile:access ability.
        if ($presentedBearer && ! $candidate instanceof PersonalAccessToken) {
            return $this->error($request, 401, 'mobile_token_invalid', 'This Android access token is invalid or has been revoked.');
        }

        $isConfig = $request->is('api/mobile/v1/config');
        $isOAuthCallback = $request->is('api/mobile/v1/auth/oauth/*/callback');
        $compatExempt = $isConfig || $isOAuthCallback;
        $version = trim((string) $request->header('X-App-Version'));
        $deviceId = trim((string) $request->header('X-Device-Id'));
        $minimum = trim((string) config('vsn.mobile.minimum_android_version', '1.0.0'));
        $latest = trim((string) config('vsn.mobile.latest_android_version', $minimum));

        if (! $compatExempt && (bool) config('vsn.mobile.maintenance_enabled', false)) {
            return response()->json(['error' => [
                'code' => 'maintenance',
                'message' => (string) (config('vsn.mobile.maintenance_message') ?: 'The service is temporarily unavailable for maintenance.'),
                'requestId' => $request->attributes->get('request_id'),
            ]], 503)->header('Retry-After', (string) max(60, (int) config('vsn.mobile.maintenance_retry_after_seconds', 300)));
        }

        if (! $compatExempt && $version === '') {
            return $this->error($request, 400, 'app_version_required', 'X-App-Version is required for Android API requests.');
        }
        if (! $compatExempt && ! preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
            return $this->error($request, 400, 'app_version_invalid', 'X-App-Version must use semantic version format such as 1.2.3.');
        }
        if (! $compatExempt && $deviceId === '') {
            return $this->error($request, 400, 'device_id_required', 'X-Device-Id is required for Android API requests.');
        }
        if (! $compatExempt && $minimum !== '' && version_compare($version, $minimum, '<')) {
            return response()->json(['error' => [
                'code' => 'app_update_required',
                'message' => 'This Android app version is no longer supported. Please update the app.',
                'minimumVersion' => $minimum,
                'latestVersion' => $latest,
                'storeUrl' => config('vsn.mobile.android_store_url'),
                'requestId' => $request->attributes->get('request_id'),
            ]], 426);
        }

        if ($candidate instanceof PersonalAccessToken) {
            $session = MobileApiSession::query()
                ->where('access_token_id', $candidate->getKey())
                ->whereNull('revoked_at')
                ->whereNull('compromised_at')
                ->first();

            if (! $session) {
                return $this->error($request, 401, 'mobile_session_revoked', 'This Android session is no longer active.');
            }
            if ($deviceId === '' || ! hash_equals($session->device_key_hash, hash('sha256', $deviceId))) {
                return $this->error($request, 401, 'mobile_device_mismatch', 'This access token belongs to a different Android installation.');
            }
            $request->attributes->set('mobile_session', $session);
        }

        $response = $next($request);

        $session = $request->attributes->get('mobile_session');
        if ($session instanceof MobileApiSession && (! $session->last_seen_at || $session->last_seen_at->lt(now()->subMinutes(5)))) {
            $session->forceFill([
                'last_seen_at' => now(),
                'last_ip' => $request->ip(),
                'app_version' => $version ?: $session->app_version,
            ])->save();
        }

        $response->headers->set('X-VSN-API-Version', '1');
        $response->headers->set('X-VSN-Release', (string) config('vsn.operations.release', 'unknown'));
        if ($minimum !== '') {
            $response->headers->set('X-VSN-Min-App-Version', $minimum);
        }
        if ($latest !== '') {
            $response->headers->set('X-VSN-Latest-App-Version', $latest);
        }
        if (! $compatExempt && $latest !== '' && version_compare($version, $latest, '<')) {
            $response->headers->set('X-VSN-App-Update-Available', '1');
        }

        return $response;
    }

    /** Handles error for the mobile client context workflow. */
    private function error(Request $request, int $status, string $code, string $message): Response
    {
        return response()->json(['error' => [
            'code' => $code,
            'message' => $message,
            'requestId' => $request->attributes->get('request_id'),
        ]], $status);
    }
}
