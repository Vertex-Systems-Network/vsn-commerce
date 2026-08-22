<?php

namespace Tests\Feature\Mobile;

use App\Domain\Notifications\Actions\DispatchNotificationDeliveries;
use App\Models\MarketplaceNotification;
use App\Models\MobileApiSession;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Defines the MobilePushDeliveryTest class and its project responsibilities. */
class MobilePushDeliveryTest extends TestCase
{
    use RefreshDatabase;

    /** Verifies push delivery is disabled cleanly when fcm is not configured. */
    public function test_push_delivery_is_disabled_cleanly_when_fcm_is_not_configured(): void
    {
        config(['vsn.mobile.fcm.project_id' => null, 'vsn.mobile.fcm.service_account_path' => null]);
        [$notification, $delivery] = $this->notificationWithPushDelivery();

        $result = app(DispatchNotificationDeliveries::class)->execute(10);

        $this->assertSame(1, $result['disabled']);
        $this->assertSame('disabled', $delivery->fresh()->status);
        $this->assertStringContainsString('not configured', (string) $delivery->fresh()->last_error);
    }

    /** Verifies unregistered fcm token is retired from mobile session. */
    public function test_unregistered_fcm_token_is_retired_from_mobile_session(): void
    {
        if (! function_exists('openssl_pkey_new')) $this->markTestSkipped('OpenSSL is required for FCM service-account signing.');

        $private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($private);
        openssl_pkey_export($private, $privatePem);
        $path = storage_path('framework/testing/fcm-au-service-account.json');
        if (! is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
        file_put_contents($path, json_encode([
            'client_email' => 'fcm-au@example.test',
            'private_key' => $privatePem,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR));

        config([
            'vsn.mobile.fcm.project_id' => 'vsn-au-test',
            'vsn.mobile.fcm.service_account_path' => $path,
        ]);
        Cache::flush();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'oauth-test-token', 'expires_in' => 3600], 200),
            'https://fcm.googleapis.com/v1/projects/vsn-au-test/messages:send' => Http::response([
                'error' => [
                    'code' => 404,
                    'status' => 'NOT_FOUND',
                    'details' => [[
                        '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                        'errorCode' => 'UNREGISTERED',
                    ]],
                ],
            ], 404),
        ]);

        [$notification, $delivery, $user] = $this->notificationWithPushDelivery();
        $session = MobileApiSession::create([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'device_key_hash' => hash('sha256', '550e8400-e29b-41d4-a716-446655440000'),
            'device_name' => 'FCM test phone',
            'platform' => 'android',
            'refresh_expires_at' => now()->addDays(30),
            'push_provider' => 'fcm',
            'push_token' => 'expired-fcm-registration-token-value-1234567890',
            'push_token_hash' => hash('sha256', 'expired-fcm-registration-token-value-1234567890'),
            'push_token_updated_at' => now(),
        ]);

        $result = app(DispatchNotificationDeliveries::class)->execute(10);

        $this->assertSame(1, $result['disabled']);
        $this->assertNull($session->fresh()->push_token_hash);
        $this->assertSame('disabled', $delivery->fresh()->status);
        Http::assertSentCount(2);
        @unlink($path);
    }

    /** Handles notification with push delivery for the mobile push delivery test workflow. */
    private function notificationWithPushDelivery(): array
    {
        $user = User::factory()->create();
        $notification = MarketplaceNotification::create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'category' => 'orders',
            'type' => 'order.updated',
            'title' => 'Order update',
            'body' => 'Your order status changed.',
            'action_url' => '/account/orders',
            'dedup_key' => 'au-push-'.Str::uuid(),
            'in_app_visible' => true,
        ]);
        $delivery = NotificationDelivery::create([
            'marketplace_notification_id' => $notification->id,
            'channel' => 'push',
            'status' => 'pending',
            'available_at' => now(),
        ]);

        return [$notification, $delivery, $user];
    }
}
