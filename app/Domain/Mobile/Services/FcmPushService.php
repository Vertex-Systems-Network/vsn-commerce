<?php

namespace App\Domain\Mobile\Services;

use App\Models\MarketplaceNotification;
use App\Models\MobileApiSession;
use App\Models\NotificationDelivery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Defines the FcmPushService class and its project responsibilities. */
class FcmPushService
{
    /** Handles configured for the fcm push service workflow. */
    public function configured(): bool
    {
        if (! function_exists('openssl_sign') || ! filled(config('vsn.mobile.fcm.project_id')) || $this->serviceAccountPath() === null) {
            return false;
        }
        try {
            $account = $this->serviceAccount();
            return filled($account['client_email'] ?? null) && filled($account['private_key'] ?? null);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Handles deliver for the fcm push service workflow. */
    public function deliver(MarketplaceNotification $notification, NotificationDelivery $delivery): array
    {
        if (! $this->configured()) {
            return ['sent' => 0, 'invalidated' => 0, 'disabled' => true, 'messageIds' => []];
        }

        $metadata = $delivery->metadata ?? [];
        $alreadySent = array_values(array_unique(array_map('strval', $metadata['pushSentSessionIds'] ?? [])));
        $messageIds = is_array($metadata['pushMessageIds'] ?? null) ? $metadata['pushMessageIds'] : [];
        $sent = 0;
        $invalidated = 0;

        $sessions = MobileApiSession::query()
            ->where('user_id', $notification->user_id)
            ->whereNull('revoked_at')
            ->whereNull('compromised_at')
            ->whereNotNull('push_token_hash')
            ->whereNotNull('push_token')
            ->orderBy('id')
            ->get();

        foreach ($sessions as $session) {
            $sessionId = (string) $session->public_id;
            if (in_array($sessionId, $alreadySent, true)) continue;

            $result = $this->sendToToken((string) $session->push_token, $notification);
            if ($result['invalid']) {
                $session->forceFill([
                    'push_token' => null,
                    'push_token_hash' => null,
                    'push_provider' => null,
                    'push_token_updated_at' => null,
                ])->save();
                $invalidated++;
                continue;
            }

            $alreadySent[] = $sessionId;
            if ($result['messageId']) $messageIds[$sessionId] = $result['messageId'];
            $sent++;

            // Persist progress after every successful device send. If a later device has a transient
            // error, the notification retry will not duplicate pushes already accepted by FCM.
            $delivery->forceFill(['metadata' => array_merge($metadata, [
                'provider' => 'fcm_http_v1',
                'pushSentSessionIds' => $alreadySent,
                'pushMessageIds' => $messageIds,
            ])])->save();
            $metadata = $delivery->metadata ?? $metadata;
        }

        return [
            'sent' => $sent,
            'invalidated' => $invalidated,
            'disabled' => $sessions->isEmpty(),
            'messageIds' => $messageIds,
        ];
    }

    /** Handles send to token for the fcm push service workflow. */
    private function sendToToken(string $registrationToken, MarketplaceNotification $notification): array
    {
        $project = (string) config('vsn.mobile.fcm.project_id');
        $url = 'https://fcm.googleapis.com/v1/projects/'.rawurlencode($project).'/messages:send';
        $action = $notification->action_url ?: '/';
        $data = [
            'notificationId' => (string) $notification->public_id,
            'category' => (string) $notification->category,
            'actionUrl' => (string) $action,
        ];

        $response = Http::asJson()
            ->withToken($this->accessToken())
            ->timeout(max(3, (int) config('vsn.mobile.fcm.timeout_seconds', 10)))
            ->post($url, ['message' => [
                'token' => $registrationToken,
                'notification' => [
                    'title' => mb_substr((string) $notification->title, 0, 180),
                    'body' => mb_substr((string) $notification->body, 0, 1200),
                ],
                'data' => $data,
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'channel_id' => (string) config('vsn.mobile.fcm.channel_id', 'vsn_general'),
                        'click_action' => 'VSN_NOTIFICATION_OPEN',
                    ],
                ],
            ]]);

        if ($response->successful()) {
            return ['invalid' => false, 'messageId' => $response->json('name')];
        }

        $fcmCode = null;
        foreach ((array) $response->json('error.details', []) as $detail) {
            if (($detail['@type'] ?? null) === 'type.googleapis.com/google.firebase.fcm.v1.FcmError') {
                $fcmCode = $detail['errorCode'] ?? null;
            }
        }

        if ($fcmCode === 'UNREGISTERED' || ($fcmCode === 'INVALID_ARGUMENT' && $response->status() === 400)) {
            return ['invalid' => true, 'messageId' => null];
        }

        throw new RuntimeException('FCM push failed with HTTP '.$response->status().': '.mb_substr((string) $response->body(), 0, 1000));
    }

    /** Handles access token for the fcm push service workflow. */
    private function accessToken(): string
    {
        $account = $this->serviceAccount();
        $cacheKey = 'vsn:fcm:oauth:'.hash('sha256', (string) ($account['client_email'] ?? '').'|'.(string) config('vsn.mobile.fcm.project_id'));

        return Cache::remember($cacheKey, now()->addMinutes(50), /** Inline callback for this operation. */ function () use ($account): string {
            $tokenUri = (string) ($account['token_uri'] ?? 'https://oauth2.googleapis.com/token');
            $now = time();
            $header = $this->b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
            $claims = $this->b64(json_encode([
                'iss' => (string) ($account['client_email'] ?? ''),
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ], JSON_THROW_ON_ERROR));
            $input = $header.'.'.$claims;
            $signature = '';
            $privateKey = (string) ($account['private_key'] ?? '');
            if ($privateKey === '' || ! openssl_sign($input, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Unable to sign the Firebase service-account assertion.');
            }
            $jwt = $input.'.'.$this->b64($signature);

            $response = Http::asForm()->timeout(10)->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
            if (! $response->successful() || ! filled($response->json('access_token'))) {
                throw new RuntimeException('Unable to obtain Firebase OAuth access token.');
            }
            return (string) $response->json('access_token');
        });
    }

    /** Handles service account for the fcm push service workflow. */
    private function serviceAccount(): array
    {
        $path = $this->serviceAccountPath();
        if ($path === null) throw new RuntimeException('FCM service account is not configured.');
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
            throw new RuntimeException('FCM service-account JSON is incomplete.');
        }
        return $decoded;
    }

    /** Handles service account path for the fcm push service workflow. */
    private function serviceAccountPath(): ?string
    {
        $value = trim((string) config('vsn.mobile.fcm.service_account_path'));
        if ($value === '') return null;
        $path = str_starts_with($value, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $value)
            ? $value
            : base_path($value);
        if (! is_file($path) || ! is_readable($path)) return null;
        $real = realpath($path);
        $public = realpath(public_path());
        if ($real === false || ($public !== false && str_starts_with($real, rtrim($public, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))) {
            return null;
        }
        return $real;
    }

    /** Handles b64 for the fcm push service workflow. */
    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
