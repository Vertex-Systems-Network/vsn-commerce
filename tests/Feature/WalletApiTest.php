<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Defines the WalletApiTest class and its project responsibilities. */
class WalletApiTest extends TestCase
{
    use RefreshDatabase;

    /** Updates up. */
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'vsn.payments.methods.card.enabled' => true,
            'vsn.payments.methods.card.provider' => 'sandbox',
            'vsn.payments.providers.sandbox.webhook_secret' => 'wallet-test-secret',
            'vsn.payments.providers.sandbox.simulator_enabled' => true,
        ]);
    }

    /** Verifies daily checkin credits once and duplicate claim fails. */
    public function test_daily_checkin_credits_once_and_duplicate_claim_fails(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/wallet/check-in', [])
            ->assertOk()
            ->assertJsonPath('data.baseRewardCoins', 70)
            ->assertJsonPath('data.bonusRewardCoins', 0);

        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance_coins' => 70]);
        $this->assertDatabaseHas('wallet_transactions', ['type' => 'daily_checkin']);

        $this->postJson('/api/v1/wallet/check-in', [])->assertUnprocessable();
        $this->assertDatabaseCount('daily_checkins', 1);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    /** Verifies seventh consecutive checkin adds 350 bonus. */
    public function test_seventh_consecutive_checkin_adds_350_bonus(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        Carbon::setTestNow(Carbon::parse('2026-08-01 09:00:00'));

        for ($day = 0; $day < 7; $day++) {
            Carbon::setTestNow(Carbon::parse('2026-08-01 09:00:00')->addDays($day));
            $response = $this->postJson('/api/v1/wallet/check-in', [])->assertOk();
            if ($day === 6) {
                $response->assertJsonPath('data.streakDay', 7)->assertJsonPath('data.bonusRewardCoins', 350);
            }
        }

        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance_coins' => 840]);
        $this->assertDatabaseHas('wallet_transactions', ['type' => 'streak_bonus']);
        Carbon::setTestNow();
    }

    /** Verifies transfer is atomic and idempotent. */
    public function test_transfer_is_atomic_and_idempotent(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        Wallet::create(['user_id' => $sender->id, 'balance_coins' => 1000, 'reserved_coins' => 0]);
        Wallet::create(['user_id' => $recipient->id, 'balance_coins' => 100, 'reserved_coins' => 0]);
        Sanctum::actingAs($sender);

        $payload = ['recipient' => $recipient->email, 'coins' => 300, 'idempotencyKey' => 'wallet-transfer-001'];
        $this->postJson('/api/v1/wallet/transfers', $payload)->assertOk()->assertJsonPath('data.coins', 300);
        $this->postJson('/api/v1/wallet/transfers', $payload)->assertOk();

        $this->assertDatabaseHas('wallets', ['user_id' => $sender->id, 'balance_coins' => 700]);
        $this->assertDatabaseHas('wallets', ['user_id' => $recipient->id, 'balance_coins' => 400]);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseCount('wallet_entries', 2);
    }

    /** Verifies coin purchase is credited only after verified payment and only once. */
    public function test_coin_purchase_is_credited_only_after_verified_payment_and_only_once(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $purchase = $this->postJson('/api/v1/wallet/coin-purchases', [
            'coins' => 700,
            'idempotencyKey' => 'wallet-purchase-001',
        ])->assertOk()
            ->assertJsonPath('data.amountMinor', 1000)
            ->assertJsonPath('data.status', 'requires_action')
            ->json('data');

        $this->assertDatabaseMissing('wallets', ['user_id' => $user->id, 'balance_coins' => 700]);

        $paymentId = $purchase['payment']['id'];
        $this->postJson("/api/v1/payments/{$paymentId}/sandbox/complete", [])->assertOk();
        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance_coins' => 700]);
        $this->assertDatabaseHas('coin_purchases', ['user_id' => $user->id, 'status' => 'paid']);
        $this->assertDatabaseHas('wallet_transactions', ['type' => 'coin_purchase']);

        $this->postJson("/api/v1/payments/{$paymentId}/sandbox/complete", [])->assertOk();
        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance_coins' => 700]);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    /** Verifies coin purchase idempotency key cannot cross users. */
    public function test_coin_purchase_idempotency_key_cannot_cross_users(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        Sanctum::actingAs($first);
        $this->postJson('/api/v1/wallet/coin-purchases', [
            'coins' => 700, 'idempotencyKey' => 'cross-user-purchase-key',
        ])->assertOk();

        Sanctum::actingAs($second);
        $this->postJson('/api/v1/wallet/coin-purchases', [
            'coins' => 700, 'idempotencyKey' => 'cross-user-purchase-key',
        ])->assertUnprocessable()->assertJsonPath('errors.idempotencyKey.0', 'Idempotency key is already owned by another coin purchase.');
    }

    /** Verifies wallet summary exposes available balance after hold. */
    public function test_wallet_summary_exposes_available_balance_after_hold(): void
    {
        $user = User::factory()->create();
        Wallet::create(['user_id' => $user->id, 'balance_coins' => 1000, 'reserved_coins' => 300]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/wallet')
            ->assertOk()
            ->assertJsonPath('data.balanceCoins', 1000)
            ->assertJsonPath('data.reservedCoins', 300)
            ->assertJsonPath('data.availableCoins', 700);
    }
}
