<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Domain\Affiliate\Actions\AttachReferrer;
use App\Domain\Affiliate\Exceptions\AffiliateException;
use App\Domain\Mobile\Services\MobileTokenService;
use App\Domain\Security\Services\DeviceTracker;
use App\Domain\Security\Services\SecurityRecorder;
use App\Enums\SecuritySeverity;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\MobileApiSession;
use App\Models\OneTimeCode;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/** Defines the MobileAuthController class and its project responsibilities. */
class MobileAuthController extends Controller
{
    /** Handles register for the mobile auth controller workflow. */
    public function register(Request $request, AttachReferrer $attachReferrer, MobileTokenService $tokens, SecurityRecorder $events, DeviceTracker $devices): JsonResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate(array_merge($this->deviceRules(), [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(10)->mixedCase()->numbers()],
            'referralCode' => ['nullable', 'string', 'max:24'],
        ]));
        $data['email'] = Str::lower(trim((string) $data['email']));

        try {
            $user = DB::transaction(/** Inline callback for this operation. */ function () use ($data, $attachReferrer): User {
                $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
                $user->profile()->create();
                if (! empty($data['referralCode'])) $attachReferrer->execute($user, $data['referralCode']);
                return $user;
            }, 3);
        } catch (AffiliateException $exception) {
            throw ValidationException::withMessages(['referralCode' => [$exception->getMessage()]]);
        }

        event(new Registered($user));
        $this->prepareDeviceHeader($request, $data);
        $events->record($user, 'mobile_account_registered', SecuritySeverity::Low, $request);
        $devices->touch($user, $request);
        $auth = $tokens->issue($user, $request, $data);

        return response()->json(['data' => [
            'user' => (new UserResource($user->load('profile')))->resolve($request),
            'auth' => $auth,
        ]], 201);
    }

    /** Handles login for the mobile auth controller workflow. */
    public function login(Request $request, MobileTokenService $tokens, SecurityRecorder $events, DeviceTracker $devices): JsonResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate(array_merge($this->deviceRules(), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]));
        $email = Str::lower(trim((string) $data['email']));
        $key = 'mobile-login:'.$email.'|'.$request->ip().'|'.hash('sha256', (string) $data['deviceId']);

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => ['Too many sign-in attempts. Try again shortly.']]);
        }

        $user = User::query()->where('email', $email)->first();
        $valid = $user && Hash::check((string) $data['password'], (string) $user->password);

        if (! $valid || ! $user) {
            RateLimiter::hit($key, 60);
            if ($user) $events->record($user, 'mobile_login_failed', SecuritySeverity::Medium, $request);
            throw ValidationException::withMessages(['email' => ['The provided credentials are invalid.']]);
        }

        RateLimiter::clear($key);
        $this->prepareDeviceHeader($request, $data);
        $events->record($user, 'mobile_login_succeeded', SecuritySeverity::Low, $request);
        $devices->touch($user, $request);
        $auth = $tokens->issue($user, $request, $data);

        return response()->json(['data' => [
            'user' => (new UserResource($user->load('profile')))->resolve($request),
            'auth' => $auth,
        ]]);
    }

    /** Handles verify otp for the mobile auth controller workflow. */
    public function verifyOtp(Request $request, MobileTokenService $tokens, SecurityRecorder $events, DeviceTracker $devices): JsonResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate(array_merge($this->deviceRules(), [
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
        ]));
        $email = Str::lower(trim((string) $data['email']));

        $otp = OneTimeCode::query()->where('purpose', 'login')->where('identifier', $email)
            ->whereNull('consumed_at')->where('expires_at', '>', now())->latest('id')->first();

        if (! $otp || $otp->attempts >= 5 || ! Hash::check((string) $data['code'], $otp->code_hash)) {
            if ($otp) $otp->increment('attempts');
            throw ValidationException::withMessages(['code' => ['The one-time code is invalid or expired.']]);
        }

        $otp->update(['consumed_at' => now()]);
        $user = User::query()->where('email', $email)->firstOrFail();
        $this->prepareDeviceHeader($request, $data);
        $events->record($user, 'mobile_otp_login_succeeded', SecuritySeverity::Low, $request);
        $devices->touch($user, $request);

        return response()->json(['data' => [
            'user' => (new UserResource($user->load('profile')))->resolve($request),
            'auth' => $tokens->issue($user, $request, $data),
        ]]);
    }

    /** Handles refresh for the mobile auth controller workflow. */
    public function refresh(Request $request, MobileTokenService $tokens): JsonResponse
    {
        $data = $request->validate(array_merge($this->deviceRules(), [
            'refreshToken' => ['required', 'string', 'min:40', 'max:500'],
        ]));
        $this->prepareDeviceHeader($request, $data);
        return response()->json(['data' => ['auth' => $tokens->refresh((string) $data['refreshToken'], $request, $data)]]);
    }

    /** Handles me for the mobile auth controller workflow. */
    public function me(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        $session = $token instanceof PersonalAccessToken
            ? MobileApiSession::query()->where('access_token_id', $token->getKey())->first()
            : null;

        return response()->json(['data' => [
            'user' => (new UserResource($request->user()->load('profile')))->resolve($request),
            'session' => $session ? $this->sessionRow($session, true) : null,
        ]]);
    }

    /** Handles logout for the mobile auth controller workflow. */
    public function logout(Request $request, MobileTokenService $tokens, SecurityRecorder $events): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        $session = $token instanceof PersonalAccessToken
            ? MobileApiSession::query()->where('access_token_id', $token->getKey())->first()
            : null;
        if ($session) $tokens->revoke($session);
        else $token?->delete();
        $events->record($request->user(), 'mobile_logout', SecuritySeverity::Low, $request);
        return response()->json(['data' => ['ok' => true]]);
    }

    /** Handles logout all for the mobile auth controller workflow. */
    public function logoutAll(Request $request, MobileTokenService $tokens, SecurityRecorder $events): JsonResponse
    {
        $count = $tokens->revokeAll($request->user());
        $events->record($request->user(), 'mobile_logout_all', SecuritySeverity::Medium, $request, ['revokedSessions' => $count]);
        return response()->json(['data' => ['ok' => true, 'revokedSessions' => $count]]);
    }

    /** Handles device rules for the mobile auth controller workflow. */
    private function deviceRules(): array
    {
        return [
            'deviceId' => ['required', 'string', 'min:16', 'max:190'],
            'deviceName' => ['required', 'string', 'max:120'],
            'appVersion' => ['required', 'string', 'max:40'],
            'osVersion' => ['nullable', 'string', 'max:80'],
        ];
    }

    /** Handles prepare device header for the mobile auth controller workflow. */
    private function prepareDeviceHeader(Request $request, array $data): void
    {
        $headerDevice = trim((string) $request->header('X-Device-Id'));
        $headerVersion = trim((string) $request->header('X-App-Version'));
        if ($headerDevice !== '' && ! hash_equals($headerDevice, (string) $data['deviceId'])) {
            throw ValidationException::withMessages(['deviceId' => ['X-Device-Id must match the deviceId request field.']]);
        }
        if ($headerVersion !== '' && ! hash_equals($headerVersion, (string) $data['appVersion'])) {
            throw ValidationException::withMessages(['appVersion' => ['X-App-Version must match the appVersion request field.']]);
        }
        $request->headers->set('X-Device-Id', (string) $data['deviceId']);
        $request->headers->set('X-App-Version', (string) $data['appVersion']);
        $request->headers->set('X-VSN-Client', 'android');
    }

    /** Handles session row for the mobile auth controller workflow. */
    private function sessionRow(MobileApiSession $session, bool $current = false): array
    {
        return [
            'id' => $session->public_id,
            'deviceName' => $session->device_name,
            'platform' => $session->platform,
            'appVersion' => $session->app_version,
            'osVersion' => $session->os_version,
            'pushEnabled' => filled($session->push_token_hash),
            'pushTokenUpdatedAt' => $session->push_token_updated_at?->toIso8601String(),
            'refreshGeneration' => (int) $session->refresh_generation,
            'lastSeenAt' => $session->last_seen_at?->toIso8601String(),
            'refreshExpiresAt' => $session->refresh_expires_at?->toIso8601String(),
            'compromisedAt' => $session->compromised_at?->toIso8601String(),
            'compromiseReason' => $session->compromise_reason,
            'revokedAt' => $session->revoked_at?->toIso8601String(),
            'current' => $current,
        ];
    }
}
