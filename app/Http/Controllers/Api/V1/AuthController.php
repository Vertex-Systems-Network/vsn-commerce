<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Affiliate\Actions\AttachReferrer;
use App\Domain\Affiliate\Exceptions\AffiliateException;
use App\Domain\Security\Services\DeviceTracker;
use App\Domain\Security\Services\SecurityRecorder;
use App\Enums\SecuritySeverity;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\OneTimeCode;
use App\Models\User;
use App\Notifications\LoginOtpNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Defines the AuthController class and its project responsibilities. */
class AuthController extends Controller
{
    /** Handles me for the auth controller workflow. */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('profile'));
    }

    /** Handles register for the auth controller workflow. */
    public function register(RegisterRequest $request, AttachReferrer $attachReferrer, SecurityRecorder $events, DeviceTracker $devices): JsonResponse
    {
        $data = $request->validated();

        try {
            $user = DB::transaction(/** Inline callback for this operation. */ function () use ($data, $attachReferrer): User {
                $user = User::create([
                    'name' => $data['name'],
                    'email' => Str::lower($data['email']),
                    'password' => $data['password'],
                ]);
                $user->profile()->create();
                if (! empty($data['referral_code'])) {
                    $attachReferrer->execute($user, $data['referral_code']);
                }
                return $user;
            }, 3);
        } catch (AffiliateException $exception) {
            throw ValidationException::withMessages([
                'referral_code' => [$exception->getMessage()],
            ]);
        }

        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();
        $events->record($user, 'account_registered', SecuritySeverity::Low, $request);
        $devices->touch($user, $request);

        return (new UserResource($user->load('profile')))
            ->response()
            ->setStatusCode(201);
    }

    /** Handles login for the auth controller workflow. */
    public function login(LoginRequest $request, SecurityRecorder $events, DeviceTracker $devices): UserResource
    {
        $credentials = $request->safe()->only(['email', 'password']);
        $key = 'login:'.Str::lower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $candidate = User::query()->where('email', Str::lower($credentials['email']))->first();
            if ($candidate) $events->record($candidate, 'login_rate_limited', SecuritySeverity::High, $request);
            throw ValidationException::withMessages([
                'email' => ['Too many sign-in attempts. Try again shortly.'],
            ]);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            $candidate = User::query()->where('email', Str::lower($credentials['email']))->first();
            RateLimiter::hit($key, 60);
            if ($candidate) $events->record($candidate, 'login_failed', SecuritySeverity::Medium, $request);
            throw ValidationException::withMessages(['email' => ['The provided credentials are invalid.']]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $events->record($request->user(), 'login_succeeded', SecuritySeverity::Low, $request);
        $devices->touch($request->user(), $request);

        return new UserResource($request->user()->load('profile'));
    }

    /** Handles logout for the auth controller workflow. */
    public function logout(Request $request, SecurityRecorder $events): JsonResponse
    {
        if ($request->user()) $events->record($request->user(), 'logout', SecuritySeverity::Low, $request);
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['ok' => true]]);
    }

    /** Handles forgot password for the auth controller workflow. */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink(['email' => Str::lower($request->string('email')->toString())]);

        return response()->json([
            'data' => ['message' => 'If the account exists, reset instructions have been sent.'],
        ]);
    }


/** Handles reset password for the auth controller workflow. */
public function resetPassword(Request $request): JsonResponse
{
    $data = $request->validate([
        'email' => ['required', 'email'],
        'token' => ['required', 'string'],
        'password' => ['required', 'confirmed', 'min:10'],
    ]);

    $status = Password::reset(
        [
            'email' => Str::lower($data['email']),
            'password' => $data['password'],
            'password_confirmation' => $data['password_confirmation'],
            'token' => $data['token'],
        ],
        /** Inline callback for this operation. */ function (User $user, string $password): void {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();
        }
    );

    if ($status !== Password::PASSWORD_RESET) {
        throw ValidationException::withMessages([
            'email' => [__($status)],
        ]);
    }

    return response()->json([
        'data' => ['message' => 'Password reset successfully.'],
    ]);
}

    /** Handles send otp for the auth controller workflow. */
    public function sendOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $email = Str::lower($data['email']);
        $rateKey = 'email-otp:'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($rateKey, 3)) {
            throw ValidationException::withMessages([
                'email' => ['Too many code requests. Try again later.'],
            ]);
        }

        RateLimiter::hit($rateKey, 600);

        OneTimeCode::query()
            ->where('purpose', 'login')
            ->where('identifier', $email)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $user = User::query()->where('email', $email)->first();

        if ($user) {
            $code = (string) random_int(100000, 999999);

            OneTimeCode::create([
                'purpose' => 'login',
                'identifier' => $email,
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(10),
            ]);

            Notification::route('mail', $email)->notify(new LoginOtpNotification($code));
        }

        return response()->json([
            'data' => ['message' => 'If the account exists, a one-time code has been sent.'],
        ]);
    }

    /** Handles verify otp for the auth controller workflow. */
    public function verifyOtp(Request $request, SecurityRecorder $events, DeviceTracker $devices): UserResource
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
        ]);

        $email = Str::lower($data['email']);

        $otp = OneTimeCode::query()
            ->where('purpose', 'login')
            ->where('identifier', $email)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $otp || $otp->attempts >= 5 || ! Hash::check($data['code'], $otp->code_hash)) {
            if ($otp) {
                $otp->increment('attempts');
            }

            throw ValidationException::withMessages([
                'code' => ['The one-time code is invalid or expired.'],
            ]);
        }

        $otp->update(['consumed_at' => now()]);
        $user = User::query()->where('email', $email)->firstOrFail();

        Auth::login($user);
        $request->session()->regenerate();
        $events->record($user, 'otp_login_succeeded', SecuritySeverity::Low, $request);
        $devices->touch($user, $request);

        return new UserResource($user->load('profile'));
    }
}
