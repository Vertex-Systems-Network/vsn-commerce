<?php

namespace Tests\Feature\Mobile;

use App\Models\MobileApiSession;
use App\Models\MobileOAuthFlow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/** Defines the MobileApiTest class and its project responsibilities. */
class MobileApiTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE_ID = '550e8400-e29b-41d4-a716-446655440000';

    /** Verifies mobile config is public and exposes compatibility contract. */
    public function test_mobile_config_is_public_and_exposes_compatibility_contract(): void
    {
        $this->withHeader('X-VSN-Client', 'android')->getJson('/api/mobile/v1/config')
            ->assertOk()->assertJsonPath('data.apiVersion', 1)
            ->assertJsonPath('data.endpoints.businessBase', '/api/v1');
    }

    /** Verifies password login issues short lived access and hash only refresh session. */
    public function test_password_login_issues_short_lived_access_and_hash_only_refresh_session(): void
    {
        $user = User::factory()->create(['email' => 'mobile@example.com', 'password' => Hash::make('StrongPass123')]);
        $response = $this->postJson('/api/mobile/v1/auth/login', $this->loginPayload(), $this->headers())->assertOk();

        $access = $response->json('data.auth.accessToken');
        $refresh = $response->json('data.auth.refreshToken');
        $this->assertNotEmpty($access);
        $this->assertNotEmpty($refresh);

        $session = MobileApiSession::query()->firstOrFail();
        $this->assertSame(hash('sha256', $refresh), $session->refresh_token_hash);
        $this->assertNotSame($refresh, $session->refresh_token_hash);

        $token = PersonalAccessToken::query()->findOrFail($session->access_token_id);
        $this->assertTrue($token->can('mobile:access'));
        $this->assertSame($user->id, $session->user_id);
    }

    /** Verifies access token authenticates existing business api. */
    public function test_access_token_authenticates_existing_business_api(): void
    {
        User::factory()->create(['email' => 'mobile@example.com', 'password' => Hash::make('StrongPass123')]);
        $login = $this->postJson('/api/mobile/v1/auth/login', $this->loginPayload(), $this->headers())->json('data.auth');

        $this->withToken($login['accessToken'])->withHeaders($this->headers())
            ->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', 'mobile@example.com');
    }

    /** Verifies refresh rotates access and refresh credentials. */
    public function test_refresh_rotates_access_and_refresh_credentials(): void
    {
        User::factory()->create(['email' => 'mobile@example.com', 'password' => Hash::make('StrongPass123')]);
        $first = $this->postJson('/api/mobile/v1/auth/login', $this->loginPayload(), $this->headers())->json('data.auth');
        $payload = $this->devicePayload() + ['refreshToken' => $first['refreshToken']];
        $second = $this->postJson('/api/mobile/v1/auth/refresh', $payload, $this->headers())->assertOk()->json('data.auth');

        $this->assertNotSame($first['accessToken'], $second['accessToken']);
        $this->assertNotSame($first['refreshToken'], $second['refreshToken']);
        $this->postJson('/api/mobile/v1/auth/refresh', $payload, $this->headers())->assertStatus(422);
    }

    /** Verifies refresh token is not a sanctum business token. */
    public function test_refresh_token_is_not_a_sanctum_business_token(): void
    {
        User::factory()->create(['email' => 'mobile@example.com', 'password' => Hash::make('StrongPass123')]);
        $auth = $this->postJson('/api/mobile/v1/auth/login', $this->loginPayload(), $this->headers())->json('data.auth');

        $this->withToken($auth['refreshToken'])->withHeaders($this->headers())
            ->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    /** Verifies refresh is bound to original device id. */
    public function test_refresh_is_bound_to_original_device_id(): void
    {
        User::factory()->create(['email' => 'mobile@example.com', 'password' => Hash::make('StrongPass123')]);
        $auth = $this->postJson('/api/mobile/v1/auth/login', $this->loginPayload(), $this->headers())->json('data.auth');
        $payload = $this->devicePayload() + ['refreshToken' => $auth['refreshToken']];
        $payload['deviceId'] = '12345678-1234-1234-1234-123456789999';

        $this->postJson('/api/mobile/v1/auth/refresh', $payload, $this->headers('12345678-1234-1234-1234-123456789999'))
            ->assertStatus(422);
    }

    /** Verifies logout revokes current access and refresh session. */
    public function test_logout_revokes_current_access_and_refresh_session(): void
    {
        User::factory()->create(['email' => 'mobile@example.com', 'password' => Hash::make('StrongPass123')]);
        $auth = $this->postJson('/api/mobile/v1/auth/login', $this->loginPayload(), $this->headers())->json('data.auth');

        $this->withToken($auth['accessToken'])->withHeaders($this->headers())->postJson('/api/mobile/v1/auth/logout')->assertOk();
        $this->assertNotNull(MobileApiSession::query()->firstOrFail()->revoked_at);
        $this->withToken($auth['accessToken'])->withHeaders($this->headers())->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    /** Verifies user cannot revoke another users mobile session. */
    public function test_user_cannot_revoke_another_users_mobile_session(): void
    {
        $a = User::factory()->create(['email' => 'a@example.com', 'password' => Hash::make('StrongPass123')]);
        $b = User::factory()->create(['email' => 'b@example.com', 'password' => Hash::make('StrongPass123')]);
        $authA = $this->postJson('/api/mobile/v1/auth/login', $this->loginPayload('a@example.com'), $this->headers())->json('data.auth');
        $otherDevice = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $authB = $this->postJson('/api/mobile/v1/auth/login', $this->loginPayload('b@example.com', $otherDevice), $this->headers($otherDevice))->json('data.auth');

        $this->withToken($authA['accessToken'])->withHeaders($this->headers())
            ->deleteJson('/api/mobile/v1/sessions/'.$authB['sessionId'])->assertNotFound();
        $this->assertSame($a->id, MobileApiSession::query()->where('public_id', $authA['sessionId'])->value('user_id'));
        $this->assertSame($b->id, MobileApiSession::query()->where('public_id', $authB['sessionId'])->value('user_id'));
    }

    /** Verifies fcm token is encrypted at rest. */
    public function test_fcm_token_is_encrypted_at_rest(): void
    {
        User::factory()->create(['email' => 'mobile@example.com', 'password' => Hash::make('StrongPass123')]);
        $auth = $this->postJson('/api/mobile/v1/auth/login', $this->loginPayload(), $this->headers())->json('data.auth');
        $raw = 'fcm-registration-token-secret-value';

        $this->withToken($auth['accessToken'])->withHeaders($this->headers())
            ->putJson('/api/mobile/v1/device/push-token', ['provider' => 'fcm', 'token' => $raw])->assertOk();

        $session = MobileApiSession::query()->firstOrFail();
        $this->assertSame($raw, $session->push_token);
        $this->assertNotSame($raw, $session->getRawOriginal('push_token'));
        $this->assertSame(hash('sha256', $raw), $session->push_token_hash);
    }

    /** Verifies mobile oauth exchange code is device bound and one time. */
    public function test_mobile_oauth_exchange_code_is_device_bound_and_one_time(): void
    {
        $user = User::factory()->create();
        $code = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        MobileOAuthFlow::create([
            'public_id' => (string) Str::ulid(),
            'provider' => 'google',
            'state_hash' => hash('sha256', 'test-state'),
            'device_key_hash' => hash('sha256', self::DEVICE_ID),
            'user_id' => $user->id,
            'exchange_code_hash' => hash('sha256', $code),
            'expires_at' => now()->addMinutes(5),
            'completed_at' => now(),
        ]);
        $payload = $this->devicePayload() + ['code' => $code];
        $this->postJson('/api/mobile/v1/auth/oauth/exchange', $payload, $this->headers())
            ->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->postJson('/api/mobile/v1/auth/oauth/exchange', $payload, $this->headers())->assertStatus(422);
    }

    /** Verifies mobile access token cannot bypass android version headers by omitting client header. */
    public function test_mobile_access_token_cannot_bypass_android_version_headers_by_omitting_client_header(): void
    {
        User::factory()->create(['email' => 'mobile@example.com', 'password' => Hash::make('StrongPass123')]);
        $auth = $this->postJson('/api/mobile/v1/auth/login', $this->loginPayload(), $this->headers())->json('data.auth');
        $this->withToken($auth['accessToken'])->getJson('/api/v1/auth/me')
            ->assertStatus(400)->assertJsonPath('error.code', 'app_version_required');
    }

    /** Verifies device header and login payload must match. */
    public function test_device_header_and_login_payload_must_match(): void
    {
        User::factory()->create(['email' => 'mobile@example.com', 'password' => Hash::make('StrongPass123')]);
        $payload = $this->loginPayload();
        $payload['deviceId'] = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $this->postJson('/api/mobile/v1/auth/login', $payload, $this->headers())
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
    }

    /** Verifies mobile maintenance blocks android business requests but not config. */
    public function test_mobile_maintenance_blocks_android_business_requests_but_not_config(): void
    {
        config(['vsn.mobile.maintenance_enabled' => true, 'vsn.mobile.maintenance_message' => 'Scheduled upgrade']);
        $this->getJson('/api/v1/products', $this->headers())->assertStatus(503)->assertJsonPath('error.code', 'maintenance');
        $this->getJson('/api/mobile/v1/config', $this->headers())->assertOk()->assertJsonPath('data.maintenance.enabled', true);
    }

    /** Verifies old android version is rejected but config remains available. */
    public function test_old_android_version_is_rejected_but_config_remains_available(): void
    {
        config(['vsn.mobile.minimum_android_version' => '2.0.0']);
        $headers = $this->headers(self::DEVICE_ID, '1.0.0');
        $this->getJson('/api/v1/products', $headers)->assertStatus(426)->assertJsonPath('error.code', 'app_update_required');
        $this->getJson('/api/mobile/v1/config', $headers)->assertOk();
    }


    /** Verifies mobile access token is bound to original device on business api. */
    public function test_mobile_access_token_is_bound_to_original_device_on_business_api(): void
    {
        User::factory()->create(['email' => 'mobile@example.com', 'password' => Hash::make('StrongPass123')]);
        $auth = $this->postJson('/api/mobile/v1/auth/login', $this->loginPayload(), $this->headers())->json('data.auth');
        $wrong = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

        $this->withToken($auth['accessToken'])->withHeaders($this->headers($wrong))
            ->getJson('/api/v1/orders')
            ->assertUnauthorized()->assertJsonPath('error.code', 'mobile_device_mismatch');
    }

    /** Verifies replaying rotated refresh token revokes compromised session. */
    public function test_replaying_rotated_refresh_token_revokes_compromised_session(): void
    {
        User::factory()->create(['email' => 'mobile@example.com', 'password' => Hash::make('StrongPass123')]);
        $first = $this->postJson('/api/mobile/v1/auth/login', $this->loginPayload(), $this->headers())->json('data.auth');
        $payload = $this->devicePayload() + ['refreshToken' => $first['refreshToken']];
        $second = $this->postJson('/api/mobile/v1/auth/refresh', $payload, $this->headers())->assertOk()->json('data.auth');

        $this->postJson('/api/mobile/v1/auth/refresh', $payload, $this->headers())
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_error');

        $session = MobileApiSession::query()->where('public_id', $second['sessionId'])->firstOrFail();
        $this->assertNotNull($session->compromised_at);
        $this->assertSame('refresh_token_replay', $session->compromise_reason);
        $this->assertNull($session->access_token_id);

        $this->withToken($second['accessToken'])->withHeaders($this->headers())
            ->getJson('/api/v1/orders')->assertUnauthorized();
    }

    /** Verifies android request requires device header and semantic app version. */
    public function test_android_request_requires_device_header_and_semantic_app_version(): void
    {
        $headers = ['X-VSN-Client' => 'android', 'X-App-Version' => '1.0.0', 'Accept' => 'application/json'];
        $this->getJson('/api/v1/products', $headers)
            ->assertStatus(400)->assertJsonPath('error.code', 'device_id_required');

        $headers['X-Device-Id'] = self::DEVICE_ID;
        $headers['X-App-Version'] = 'version-one';
        $this->getJson('/api/v1/products', $headers)
            ->assertStatus(400)->assertJsonPath('error.code', 'app_version_invalid');
    }

    /** Verifies push token can be registered rotated and removed for current installation. */
    public function test_push_token_can_be_registered_rotated_and_removed_for_current_installation(): void
    {
        User::factory()->create(['email' => 'mobile@example.com', 'password' => Hash::make('StrongPass123')]);
        $auth = $this->postJson('/api/mobile/v1/auth/login', $this->loginPayload(), $this->headers())->json('data.auth');
        $token = 'fcm-registration-token-for-au-test-1234567890';

        $this->withToken($auth['accessToken'])->withHeaders($this->headers())
            ->putJson('/api/mobile/v1/device/push-token', ['provider' => 'fcm', 'token' => $token])
            ->assertOk()->assertJsonPath('data.registered', true);
        $session = MobileApiSession::query()->firstOrFail();
        $this->assertSame(hash('sha256', $token), $session->push_token_hash);
        $this->assertNotNull($session->push_token_updated_at);

        $this->withToken($auth['accessToken'])->withHeaders($this->headers())
            ->deleteJson('/api/mobile/v1/device/push-token')
            ->assertOk()->assertJsonPath('data.registered', false);
        $this->assertNull($session->fresh()->push_token_hash);
    }

    /** Verifies customer mobile access token cannot open seller operational api even for seller role. */
    public function test_customer_mobile_access_token_cannot_open_seller_operational_api_even_for_seller_role(): void
    {
        User::factory()->create([
            'email' => 'seller-mobile@example.com',
            'password' => Hash::make('StrongPass123'),
            'role' => 'seller',
        ]);
        $auth = $this->postJson('/api/mobile/v1/auth/login', $this->loginPayload('seller-mobile@example.com'), $this->headers())->json('data.auth');

        $this->withToken($auth['accessToken'])->withHeaders($this->headers())
            ->getJson('/api/v1/vendor/overview')
            ->assertForbidden();
    }

    /** Verifies customer mobile access token cannot open admin operational api even for admin role. */
    public function test_customer_mobile_access_token_cannot_open_admin_operational_api_even_for_admin_role(): void
    {
        User::factory()->create([
            'email' => 'admin-mobile@example.com',
            'password' => Hash::make('StrongPass123'),
            'role' => 'admin',
        ]);
        $auth = $this->postJson('/api/mobile/v1/auth/login', $this->loginPayload('admin-mobile@example.com'), $this->headers())->json('data.auth');

        $this->withToken($auth['accessToken'])->withHeaders($this->headers())
            ->getJson('/api/v1/admin/orders')
            ->assertForbidden();
    }

    /** Verifies mobile config exposes final security and push contract without secrets. */
    public function test_mobile_config_exposes_final_security_and_push_contract_without_secrets(): void
    {
        $response = $this->getJson('/api/mobile/v1/config')->assertOk()
            ->assertJsonPath('data.auth.deviceBound', true)
            ->assertJsonPath('data.auth.refreshRotation', true)
            ->assertJsonPath('data.auth.replayDetection', true)
            ->assertJsonPath('data.push.provider', 'fcm_http_v1');

        $this->assertArrayNotHasKey('serviceAccountPath', (array) $response->json('data.push'));
    }

    /** Handles login payload for the mobile api test workflow. */
    private function loginPayload(string $email = 'mobile@example.com', string $deviceId = self::DEVICE_ID): array
    {
        return ['email' => $email, 'password' => 'StrongPass123'] + $this->devicePayload($deviceId);
    }

    /** Handles device payload for the mobile api test workflow. */
    private function devicePayload(string $deviceId = self::DEVICE_ID): array
    {
        return ['deviceId' => $deviceId, 'deviceName' => 'Android Test Device', 'appVersion' => '1.0.0', 'osVersion' => 'Android 17'];
    }

    /** Handles headers for the mobile api test workflow. */
    private function headers(string $deviceId = self::DEVICE_ID, string $version = '1.0.0'): array
    {
        return ['X-VSN-Client' => 'android', 'X-App-Version' => $version, 'X-Device-Id' => $deviceId, 'Accept' => 'application/json'];
    }
}
