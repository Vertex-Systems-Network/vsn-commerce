<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Domain\Mobile\Services\MobileTokenService;
use App\Domain\Security\Services\DeviceTracker;
use App\Domain\Security\Services\SecurityRecorder;
use App\Enums\SecuritySeverity;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\MobileOAuthFlow;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/** Defines the MobileOAuthController class and its project responsibilities. */
class MobileOAuthController extends Controller
{
    private const PROVIDERS = ['google', 'facebook'];

    /** Handles providers for the mobile oauth controller workflow. */
    public function providers(): JsonResponse
    {
        return response()->json(['data' => [
            'google' => ['enabled' => $this->configured('google')],
            'facebook' => ['enabled' => $this->configured('facebook')],
            'apple' => ['enabled' => false],
            'linkedin' => ['enabled' => false],
        ]]);
    }

    /** Handles start for the mobile oauth controller workflow. */
    public function start(Request $request, string $provider): JsonResponse
    {
        $this->assertProvider($provider);
        abort_unless($this->configured($provider), 503, ucfirst($provider).' OAuth credentials are not configured.');
        abort_if((string) config('vsn.mobile.oauth_app_callback_url') === '', 503, 'Android OAuth app callback URL is not configured.');
        $data = $request->validate([
            'deviceId' => ['required', 'string', 'min:16', 'max:190'],
            'appVersion' => ['required', 'string', 'max:40'],
        ]);
        $this->assertDeviceHeaders($request, $data);
        $state = $this->randomToken(32);
        MobileOAuthFlow::create([
            'public_id' => (string) Str::ulid(),
            'provider' => $provider,
            'state_hash' => hash('sha256', $state),
            'device_key_hash' => hash('sha256', (string) $data['deviceId']),
            'expires_at' => now()->addMinutes(10),
        ]);

        $authorizationUrl = $this->mobileDriver($provider)
            ->with(['state' => $state])
            ->redirect()->getTargetUrl();

        return response()->json(['data' => ['authorizationUrl' => $authorizationUrl, 'provider' => $provider, 'expiresInSeconds' => 600]]);
    }

    /** Handles callback for the mobile oauth controller workflow. */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $this->assertProvider($provider);
        $appCallback = (string) config('vsn.mobile.oauth_app_callback_url');
        abort_if($appCallback === '', 503, 'Android OAuth app callback URL is not configured.');
        $state = (string) $request->query('state');
        if ($state === '') return $this->redirectError($appCallback, $provider, 'missing_state');

        $flow = MobileOAuthFlow::query()->where('provider', $provider)->where('state_hash', hash('sha256', $state))
            ->whereNull('completed_at')->where('expires_at', '>', now())->first();
        if (! $flow) return $this->redirectError($appCallback, $provider, 'invalid_or_expired_state');

        try {
            $remote = $this->mobileDriver($provider)->user();
            $user = DB::transaction(/** Inline callback for this operation. */ function () use ($provider, $remote): User {
                $social = SocialAccount::query()->where('provider', $provider)->where('provider_user_id', (string) $remote->getId())->lockForUpdate()->first();
                if ($social) return $social->user;

                $email = Str::lower((string) $remote->getEmail());
                if ($email === '') throw ValidationException::withMessages(['provider' => ['The provider did not return an email address.']]);
                $user = User::query()->firstOrCreate(['email' => $email], [
                    'name' => $remote->getName() ?: $remote->getNickname() ?: 'VSN Customer',
                    'password' => Str::password(40),
                    'email_verified_at' => now(),
                ]);
                $user->profile()->firstOrCreate();
                $user->socialAccounts()->create([
                    'provider' => $provider,
                    'provider_user_id' => (string) $remote->getId(),
                    'provider_email' => $email,
                    'metadata' => ['nickname' => $remote->getNickname(), 'avatar' => $remote->getAvatar()],
                ]);
                return $user;
            }, 3);

            $code = $this->randomToken(48);
            $flow = DB::transaction(/** Inline callback for this operation. */ function () use ($flow, $user, $code): MobileOAuthFlow {
                $locked = MobileOAuthFlow::query()->whereKey($flow->id)->lockForUpdate()->firstOrFail();
                if ($locked->completed_at || $locked->expires_at->isPast()) {
                    throw ValidationException::withMessages(['provider' => ['This OAuth flow was already completed or expired.']]);
                }
                $locked->forceFill([
                    'user_id' => $user->id,
                    'exchange_code_hash' => hash('sha256', $code),
                    'completed_at' => now(),
                ])->save();
                return $locked;
            }, 3);

            return redirect()->away($appCallback.(str_contains($appCallback, '?') ? '&' : '?').http_build_query([
                'oauth' => 'success', 'provider' => $provider, 'code' => $code,
            ]));
        } catch (Throwable $exception) {
            report($exception);
            return $this->redirectError($appCallback, $provider, 'authentication_failed');
        }
    }

    /** Handles exchange for the mobile oauth controller workflow. */
    public function exchange(Request $request, MobileTokenService $tokens, SecurityRecorder $events, DeviceTracker $devices): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'min:40', 'max:500'],
            'deviceId' => ['required', 'string', 'min:16', 'max:190'],
            'deviceName' => ['required', 'string', 'max:120'],
            'appVersion' => ['required', 'string', 'max:40'],
            'osVersion' => ['nullable', 'string', 'max:80'],
        ]);
        $this->assertDeviceHeaders($request, $data);
        $hash = hash('sha256', (string) $data['code']);

        $flow = DB::transaction(/** Inline callback for this operation. */ function () use ($hash, $data): MobileOAuthFlow {
            $flow = MobileOAuthFlow::query()->where('exchange_code_hash', $hash)->lockForUpdate()->first();
            if (! $flow || ! $flow->completed_at || $flow->consumed_at || $flow->expires_at->isPast() || ! $flow->user_id) {
                throw ValidationException::withMessages(['code' => ['The OAuth exchange code is invalid, expired, or already used.']]);
            }
            if (! hash_equals($flow->device_key_hash, hash('sha256', (string) $data['deviceId']))) {
                throw ValidationException::withMessages(['deviceId' => ['The OAuth exchange belongs to a different device.']]);
            }
            $flow->forceFill(['consumed_at' => now()])->save();
            return $flow;
        }, 3);

        $request->headers->set('X-Device-Id', (string) $data['deviceId']);
        $request->headers->set('X-App-Version', (string) $data['appVersion']);
        $request->headers->set('X-VSN-Client', 'android');
        $user = $flow->user()->firstOrFail();
        $events->record($user, 'mobile_oauth_login_succeeded', SecuritySeverity::Low, $request, ['provider' => $flow->provider]);
        $devices->touch($user, $request);

        return response()->json(['data' => [
            'user' => (new UserResource($user->load('profile')))->resolve($request),
            'auth' => $tokens->issue($user, $request, $data),
        ]]);
    }

    /** Handles assert device headers for the mobile oauth controller workflow. */
    private function assertDeviceHeaders(Request $request, array $data): void
    {
        $headerDevice = trim((string) $request->header('X-Device-Id'));
        $headerVersion = trim((string) $request->header('X-App-Version'));
        if ($headerDevice === '' || ! hash_equals($headerDevice, (string) $data['deviceId'])) {
            throw ValidationException::withMessages(['deviceId' => ['X-Device-Id must match the deviceId request field.']]);
        }
        if ($headerVersion === '' || ! hash_equals($headerVersion, (string) $data['appVersion'])) {
            throw ValidationException::withMessages(['appVersion' => ['X-App-Version must match the appVersion request field.']]);
        }
    }

    /** Handles assert provider for the mobile oauth controller workflow. */
    private function assertProvider(string $provider): void
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);
    }

    /** Handles configured for the mobile oauth controller workflow. */
    private function configured(string $provider): bool
    {
        return filled(config("services.{$provider}.client_id")) && filled(config("services.{$provider}.client_secret"));
    }

    /** Handles backend callback for the mobile oauth controller workflow. */
    private function backendCallback(string $provider): string
    {
        return rtrim((string) config('app.url'), '/').'/api/mobile/v1/auth/oauth/'.$provider.'/callback';
    }

    /** Handles mobile driver for the mobile oauth controller workflow. */
    private function mobileDriver(string $provider): mixed
    {
        // Override only this request's Socialite redirect configuration; provider credentials remain unchanged.
        config(["services.{$provider}.redirect" => $this->backendCallback($provider)]);
        return Socialite::driver($provider)->stateless();
    }

    /** Handles redirect error for the mobile oauth controller workflow. */
    private function redirectError(string $callback, string $provider, string $error): RedirectResponse
    {
        return redirect()->away($callback.(str_contains($callback, '?') ? '&' : '?').http_build_query(['oauth' => 'error', 'provider' => $provider, 'error' => $error]));
    }

    /** Handles random token for the mobile oauth controller workflow. */
    private function randomToken(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
