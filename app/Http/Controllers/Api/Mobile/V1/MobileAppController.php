<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Domain\Messaging\Services\UnreadMessageCounter;
use App\Domain\Mobile\Services\FcmPushService;
use App\Domain\Wallet\Services\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\MarketplaceNotification;
use App\Models\MobileApiSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Defines the MobileAppController class and its project responsibilities. */
class MobileAppController extends Controller
{
    /** Handles config for the mobile app controller workflow. */
    public function config(Request $request, FcmPushService $push): JsonResponse
    {
        $minimum = (string) config('vsn.mobile.minimum_android_version', '1.0.0');
        $latest = (string) config('vsn.mobile.latest_android_version', $minimum);
        $clientVersion = trim((string) $request->header('X-App-Version'));

        return response()->json(['data' => [
            'apiVersion' => 1,
            'release' => (string) config('vsn.operations.release', 'unknown'),
            'serverTime' => now()->toIso8601String(),
            'android' => [
                'minimumVersion' => $minimum,
                'latestVersion' => $latest,
                'minimumSdk' => (int) config('vsn.mobile.minimum_android_sdk', 26),
                'storeUrl' => config('vsn.mobile.android_store_url'),
                'updateAvailable' => $clientVersion !== '' && preg_match('/^\d+\.\d+\.\d+/', $clientVersion)
                    ? version_compare($clientVersion, $latest, '<')
                    : null,
            ],
            'maintenance' => [
                'enabled' => (bool) config('vsn.mobile.maintenance_enabled', false),
                'message' => config('vsn.mobile.maintenance_message'),
                'retryAfterSeconds' => max(60, (int) config('vsn.mobile.maintenance_retry_after_seconds', 300)),
            ],
            'auth' => [
                'accessTokenMinutes' => (int) config('vsn.mobile.access_token_minutes', 60),
                'refreshTokenDays' => (int) config('vsn.mobile.refresh_token_days', 30),
                'refreshRotation' => true,
                'deviceBound' => true,
                'replayDetection' => true,
            ],
            'push' => [
                'provider' => 'fcm_http_v1',
                'serverConfigured' => $push->configured(),
                'channelId' => (string) config('vsn.mobile.fcm.channel_id', 'vsn_general'),
            ],
            'endpoints' => ['mobileBase' => '/api/mobile/v1', 'businessBase' => '/api/v1'],
            'features' => [
                'catalog' => true, 'cart' => true, 'checkout' => true, 'orders' => true,
                'wallet' => true, 'affiliate' => true, 'games' => true, 'gifts' => true,
                'reviews' => true, 'returns' => true, 'messages' => true, 'notifications' => true,
                'wishlist' => true, 'kyc' => true, 'savedPayments' => true,
            ],
        ]])->header('Cache-Control', 'public, max-age=60');
    }

    /** Handles bootstrap for the mobile app controller workflow. */
    public function bootstrap(Request $request, WalletService $wallets, UnreadMessageCounter $messages): JsonResponse
    {
        $user = $request->user()->load('profile');
        $wallet = $wallets->walletFor($user);
        $notificationCount = MarketplaceNotification::query()->where('user_id', $user->id)
            ->where('in_app_visible', true)->whereNull('read_at')->count();
        $session = $request->attributes->get('mobile_session');

        return response()->json(['data' => [
            'user' => (new UserResource($user))->resolve($request),
            'session' => $session instanceof MobileApiSession ? [
                'id' => $session->public_id,
                'deviceName' => $session->device_name,
                'appVersion' => $session->app_version,
                'refreshGeneration' => (int) $session->refresh_generation,
                'pushEnabled' => filled($session->push_token_hash),
                'pushTokenUpdatedAt' => $session->push_token_updated_at?->toIso8601String(),
                'refreshExpiresAt' => $session->refresh_expires_at?->toIso8601String(),
            ] : null,
            'wallet' => [
                'balanceCoins' => (int) $wallet->balance_coins,
                'reservedCoins' => (int) $wallet->reserved_coins,
                'availableCoins' => (int) $wallet->availableCoins(),
                'coinsPerRupee' => (int) config('vsn.coins_per_rupee', 70),
            ],
            'badges' => [
                'notifications' => $notificationCount,
                'messages' => $messages->forUser($user),
            ],
            'serverTime' => now()->toIso8601String(),
        ]]);
    }
}
