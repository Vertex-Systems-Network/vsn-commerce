<?php

namespace App\Domain\Mobile\Services;

use App\Domain\Security\Services\SecurityRecorder;
use App\Enums\SecuritySeverity;
use App\Models\MobileApiSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Defines the MobileTokenService class and its project responsibilities. */
class MobileTokenService
{
    /** Initializes the MobileTokenService instance and its dependencies. */
    public function __construct(private readonly SecurityRecorder $security)
    {
    }

    /** Handles issue for the mobile token service workflow. */
    public function issue(User $user, Request $request, array $device): array
    {
        $this->assertDevice($device);

        return DB::transaction(/** Inline callback for this operation. */ function () use ($user, $request, $device): array {
            $deviceHash = hash('sha256', (string) $device['deviceId']);
            $session = MobileApiSession::query()
                ->where('user_id', $user->id)
                ->where('device_key_hash', $deviceHash)
                ->lockForUpdate()
                ->first();

            if (! $session) {
                $session = new MobileApiSession([
                    'public_id' => (string) Str::ulid(),
                    'user_id' => $user->id,
                    'device_key_hash' => $deviceHash,
                ]);
            } elseif ($session->access_token_id) {
                $user->tokens()->whereKey($session->access_token_id)->delete();
            }

            return $this->rotate($session, $user, $request, $device);
        }, 3);
    }

    /** Handles refresh for the mobile token service workflow. */
    public function refresh(string $plainRefreshToken, Request $request, array $device): array
    {
        $this->assertDevice($device);
        $hash = hash('sha256', $plainRefreshToken);

        $result = DB::transaction(/** Inline callback for this operation. */ function () use ($hash, $request, $device): array {
            $session = MobileApiSession::query()
                ->where(/** Inline callback for this operation. */ fn ($query) => $query
                    ->where('refresh_token_hash', $hash)
                    ->orWhere('previous_refresh_token_hash', $hash))
                ->lockForUpdate()
                ->first();

            if (! $session) {
                return ['_invalid' => true];
            }

            $deviceHash = hash('sha256', (string) $device['deviceId']);
            if (! hash_equals($session->device_key_hash, $deviceHash)) {
                return ['_device_mismatch' => true];
            }

            if ($session->previous_refresh_token_hash && hash_equals($session->previous_refresh_token_hash, $hash)) {
                $user = $session->user()->lockForUpdate()->firstOrFail();
                if ($session->access_token_id) $user->tokens()->whereKey($session->access_token_id)->delete();
                $session->forceFill([
                    'access_token_id' => null,
                    'refresh_token_hash' => null,
                    'previous_refresh_token_hash' => null,
                    'push_token' => null,
                    'push_token_hash' => null,
                    'push_provider' => null,
                    'push_token_updated_at' => null,
                    'revoked_at' => now(),
                    'compromised_at' => now(),
                    'compromise_reason' => 'refresh_token_replay',
                ])->save();
                return ['_replay' => true, '_user' => $user, '_session_id' => $session->public_id];
            }

            if ($session->revoked_at || $session->compromised_at || ! $session->refresh_expires_at || $session->refresh_expires_at->isPast()) {
                return ['_invalid' => true];
            }

            $user = $session->user()->lockForUpdate()->firstOrFail();
            if ($session->access_token_id) {
                $user->tokens()->whereKey($session->access_token_id)->delete();
            }

            return $this->rotate($session, $user, $request, $device);
        }, 3);

        if (! empty($result['_replay'])) {
            /** @var User $user */
            $user = $result['_user'];
            $this->security->record($user, 'mobile_refresh_replay_detected', SecuritySeverity::Critical, $request, [
                'sessionId' => $result['_session_id'],
            ]);
            throw ValidationException::withMessages(['refreshToken' => ['Refresh token replay was detected. This Android session has been revoked.']]);
        }
        if (! empty($result['_device_mismatch'])) {
            throw ValidationException::withMessages(['deviceId' => ['This refresh token belongs to a different device.']]);
        }
        if (! empty($result['_invalid'])) {
            throw ValidationException::withMessages(['refreshToken' => ['The refresh token is invalid or expired.']]);
        }

        return $result;
    }

    /** Handles revoke for the mobile token service workflow. */
    public function revoke(MobileApiSession $session): void
    {
        DB::transaction(/** Inline callback for this operation. */ function () use ($session): void {
            $locked = MobileApiSession::query()->whereKey($session->id)->lockForUpdate()->first();
            if (! $locked || $locked->revoked_at) return;
            if ($locked->access_token_id) $locked->user->tokens()->whereKey($locked->access_token_id)->delete();
            $locked->forceFill([
                'access_token_id' => null,
                'refresh_token_hash' => null,
                'previous_refresh_token_hash' => null,
                'push_token' => null,
                'push_token_hash' => null,
                'push_provider' => null,
                'push_token_updated_at' => null,
                'revoked_at' => now(),
            ])->save();
        }, 3);
    }

    /** Handles revoke all for the mobile token service workflow. */
    public function revokeAll(User $user, ?int $exceptAccessTokenId = null): int
    {
        return DB::transaction(/** Inline callback for this operation. */ function () use ($user, $exceptAccessTokenId): int {
            $query = MobileApiSession::query()->where('user_id', $user->id)->whereNull('revoked_at');
            if ($exceptAccessTokenId !== null) {
                $query->where(/** Inline callback for this operation. */ fn ($q) => $q->whereNull('access_token_id')->orWhere('access_token_id', '!=', $exceptAccessTokenId));
            }
            $sessions = $query->lockForUpdate()->get();
            foreach ($sessions as $session) {
                if ($session->access_token_id) $user->tokens()->whereKey($session->access_token_id)->delete();
                $session->forceFill([
                    'access_token_id' => null,
                    'refresh_token_hash' => null,
                    'previous_refresh_token_hash' => null,
                    'push_token' => null,
                    'push_token_hash' => null,
                    'push_provider' => null,
                    'push_token_updated_at' => null,
                    'revoked_at' => now(),
                ])->save();
            }
            return $sessions->count();
        }, 3);
    }

    /** Handles rotate for the mobile token service workflow. */
    private function rotate(MobileApiSession $session, User $user, Request $request, array $device): array
    {
        $accessMinutes = max(5, (int) config('vsn.mobile.access_token_minutes', 60));
        $refreshDays = max(1, (int) config('vsn.mobile.refresh_token_days', 30));
        $accessExpiresAt = now()->addMinutes($accessMinutes);
        $refreshExpiresAt = now()->addDays($refreshDays);
        $refreshToken = $this->randomToken();
        $previousHash = $session->refresh_token_hash;

        $access = $user->createToken(
            'android:'.$session->public_id.':'.substr((string) $device['deviceName'], 0, 60),
            ['mobile:access'],
            $accessExpiresAt,
        );

        $session->forceFill([
            'user_id' => $user->id,
            'access_token_id' => $access->accessToken->getKey(),
            'previous_refresh_token_hash' => $previousHash,
            'refresh_token_hash' => hash('sha256', $refreshToken),
            'refresh_generation' => ((int) $session->refresh_generation) + 1,
            'device_name' => substr((string) $device['deviceName'], 0, 120),
            'platform' => 'android',
            'app_version' => $device['appVersion'] ?? null,
            'os_version' => $device['osVersion'] ?? null,
            'last_ip' => $request->ip(),
            'last_seen_at' => now(),
            'refresh_expires_at' => $refreshExpiresAt,
            'last_rotated_at' => now(),
            'compromised_at' => null,
            'compromise_reason' => null,
            'revoked_at' => null,
        ])->save();

        return [
            'tokenType' => 'Bearer',
            'accessToken' => $access->plainTextToken,
            'accessExpiresAt' => $accessExpiresAt->toIso8601String(),
            'refreshToken' => $refreshToken,
            'refreshExpiresAt' => $refreshExpiresAt->toIso8601String(),
            'sessionId' => $session->public_id,
            'refreshGeneration' => (int) $session->refresh_generation,
        ];
    }

    /** Handles random token for the mobile token service workflow. */
    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    /** Handles assert device for the mobile token service workflow. */
    private function assertDevice(array $device): void
    {
        if (empty($device['deviceId']) || strlen((string) $device['deviceId']) < 16 || strlen((string) $device['deviceId']) > 190) {
            throw ValidationException::withMessages(['deviceId' => ['A stable random device ID between 16 and 190 characters is required.']]);
        }
        if (empty($device['deviceName']) || strlen((string) $device['deviceName']) > 120) {
            throw ValidationException::withMessages(['deviceName' => ['A recognizable device name is required.']]);
        }
    }
}
