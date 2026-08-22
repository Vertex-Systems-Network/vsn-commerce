<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/** Defines the SocialAuthController class and its project responsibilities. */
class SocialAuthController extends Controller
{
    private const CORE_PROVIDERS = ['google', 'facebook'];

    /** Handles providers for the social auth controller workflow. */
    public function providers(): JsonResponse
    {
        return response()->json([
            'data' => [
                'google' => ['enabled' => $this->configured('google')],
                'facebook' => ['enabled' => $this->configured('facebook')],
                'apple' => ['enabled' => false, 'reason' => 'Apple driver is reserved for the provider-adapter milestone.'],
                'linkedin' => ['enabled' => false, 'reason' => 'LinkedIn driver is reserved for the provider-adapter milestone.'],
            ],
        ]);
    }

    /** Handles start for the social auth controller workflow. */
    public function start(Request $request, string $provider): JsonResponse
    {
        abort_unless(in_array($provider, self::CORE_PROVIDERS, true), 501, ucfirst($provider).' OAuth adapter is not enabled yet.');
        abort_unless($this->configured($provider), 503, ucfirst($provider).' OAuth credentials are not configured.');

        $next = $request->string('redirect', '/dashboard')->toString();
        $request->session()->put('oauth_next', str_starts_with($next, '/') ? $next : '/dashboard');

        $authorizationUrl = Socialite::driver($provider)->redirect()->getTargetUrl();

        return response()->json([
            'data' => ['authorizationUrl' => $authorizationUrl],
        ]);
    }

    /** Handles callback for the social auth controller workflow. */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::CORE_PROVIDERS, true), 501);

        $frontend = rtrim((string) config('vsn.frontend_url'), '/');
        $next = $request->session()->pull('oauth_next', '/dashboard');

        try {
            $remote = Socialite::driver($provider)->user();

            $social = SocialAccount::query()
                ->where('provider', $provider)
                ->where('provider_user_id', (string) $remote->getId())
                ->first();

            if ($social) {
                $user = $social->user;
            } else {
                $email = Str::lower((string) $remote->getEmail());
                abort_if($email === '', 422, 'The provider did not return an email address.');

                $user = User::query()->firstOrCreate(
                    ['email' => $email],
                    [
                        'name' => $remote->getName() ?: $remote->getNickname() ?: 'VSN Customer',
                        'password' => Str::password(40),
                        'email_verified_at' => now(),
                    ]
                );

                $user->profile()->firstOrCreate();

                $social = $user->socialAccounts()->create([
                    'provider' => $provider,
                    'provider_user_id' => (string) $remote->getId(),
                    'provider_email' => $email,
                    'metadata' => [
                        'nickname' => $remote->getNickname(),
                        'avatar' => $remote->getAvatar(),
                    ],
                ]);
            }

            Auth::login($user);
            $request->session()->regenerate();

            return redirect()->away(
                $frontend.'/auth/callback?'.http_build_query([
                    'auth' => 'success',
                    'provider' => $provider,
                    'next' => $next,
                ])
            );
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->away(
                $frontend.'/auth/callback?'.http_build_query([
                    'auth' => 'error',
                    'provider' => $provider,
                    'message' => 'Social authentication could not be completed.',
                ])
            );
        }
    }

    /** Handles configured for the social auth controller workflow. */
    private function configured(string $provider): bool
    {
        return filled(config("services.{$provider}.client_id"))
            && filled(config("services.{$provider}.client_secret"))
            && filled(config("services.{$provider}.redirect"));
    }
}
