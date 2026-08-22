<?php

namespace Tests\Feature;

use App\Domain\Affiliate\Actions\AccrueAffiliateCommissions;
use App\Domain\Affiliate\Actions\AttachReferrer;
use App\Domain\Affiliate\Actions\CreditAvailableAffiliateCommissions;
use App\Domain\Affiliate\Actions\EnrollAffiliate;
use App\Domain\Affiliate\Actions\MatureAffiliateCommissions;
use App\Domain\Affiliate\Actions\ReverseOrderAffiliateCommissions;
use App\Domain\Wallet\Services\WalletService;
use App\Enums\AffiliateCommissionStatus;
use App\Enums\CartStatus;
use App\Enums\CheckoutStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\WalletTransactionType;
use App\Models\AffiliateCommission;
use App\Models\AffiliateRelationship;
use App\Models\Cart;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Defines the AffiliateApiTest class and its project responsibilities. */
class AffiliateApiTest extends TestCase
{
    use RefreshDatabase;

    /** Verifies user can enroll and receives stable referral code. */
    public function test_user_can_enroll_and_receives_stable_referral_code(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/v1/affiliate/enroll', ['acceptTerms'=>true])
            ->assertCreated()
            ->assertJsonPath('data.enrolled', true)
            ->assertJsonPath('data.account.status', 'active')
            ->json('data.account.referralCode');

        $second = $this->postJson('/api/v1/affiliate/enroll', ['acceptTerms'=>true])
            ->assertCreated()->json('data.account.referralCode');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('affiliate_accounts', 1);
    }

    /** Verifies referrer can only be attached once and self referral is blocked. */
    public function test_referrer_can_only_be_attached_once_and_self_referral_is_blocked(): void
    {
        $parent = User::factory()->create();
        $other = User::factory()->create();
        $child = User::factory()->create();
        $enroll = app(EnrollAffiliate::class);
        $parentAccount = $enroll->execute($parent, 'test');
        $otherAccount = $enroll->execute($other, 'test');
        $childAccount = $enroll->execute($child, 'test');
        Sanctum::actingAs($child);

        $this->postJson('/api/v1/affiliate/referrer', ['referralCode'=>$childAccount->referral_code])
            ->assertUnprocessable();
        $this->postJson('/api/v1/affiliate/referrer', ['referralCode'=>$parentAccount->referral_code])
            ->assertOk()->assertJsonPath('data.referrerAttached', true);
        $this->postJson('/api/v1/affiliate/referrer', ['referralCode'=>$otherAccount->referral_code])
            ->assertUnprocessable();

        $this->assertDatabaseHas('affiliate_relationships', ['user_id'=>$child->id,'parent_user_id'=>$parent->id]);
        $this->assertDatabaseCount('affiliate_relationships', 1);
    }

    /** Verifies circular referral network is rejected. */
    public function test_circular_referral_network_is_rejected(): void
    {
        $a = User::factory()->create(); $b = User::factory()->create(); $c = User::factory()->create();
        $enroll = app(EnrollAffiliate::class); $attach = app(AttachReferrer::class);
        $aa=$enroll->execute($a,'test'); $ba=$enroll->execute($b,'test'); $ca=$enroll->execute($c,'test');
        $attach->execute($b,$aa->referral_code);
        $attach->execute($c,$ba->referral_code);

        $this->expectException(\App\Domain\Affiliate\Exceptions\AffiliateException::class);
        $attach->execute($a,$ca->referral_code);
    }

    /** Verifies registration can attach referrer code atomically. */
    public function test_registration_can_attach_referrer_code_atomically(): void
    {
        $parent = User::factory()->create();
        $account = app(EnrollAffiliate::class)->execute($parent, 'test');

        $response = $this->postJson('/api/v1/auth/register', [
            'name'=>'Referral Child','email'=>'child@example.test','password'=>'StrongPass123','password_confirmation'=>'StrongPass123','referral_code'=>$account->referral_code,
        ])->assertCreated();

        $childId = User::query()->where('email','child@example.test')->value('id');
        $this->assertNotNull($childId);
        $this->assertDatabaseHas('affiliate_relationships',['user_id'=>$childId,'parent_user_id'=>$parent->id]);
        $this->assertNotEmpty($response->json('data'));
    }

    /** Verifies paid order accrues level commissions once with shipping excluded. */
    public function test_paid_order_accrues_level_commissions_once_with_shipping_excluded(): void
    {
        config(['vsn.affiliate.hold_days'=>14]);
        $l3=User::factory()->create(); $l2=User::factory()->create(); $l1=User::factory()->create(); $buyer=User::factory()->create();
        $enroll=app(EnrollAffiliate::class); $attach=app(AttachReferrer::class);
        $a3=$enroll->execute($l3,'test'); $a2=$enroll->execute($l2,'test'); $a1=$enroll->execute($l1,'test');
        $attach->execute($l2,$a3->referral_code); $attach->execute($l1,$a2->referral_code); $attach->execute($buyer,$a1->referral_code);
        $order=$this->makeOrder($buyer,PaymentStatus::Paid,100_000,10_000,25_000);

        $rows=app(AccrueAffiliateCommissions::class)->execute($order);
        app(AccrueAffiliateCommissions::class)->execute($order->fresh());

        $this->assertCount(3,$rows);
        $this->assertDatabaseCount('affiliate_commissions',3);
        // Eligible spend = Rs.900; L1=10% => Rs.90 => 6,300 coins. Shipping does not contribute.
        $this->assertDatabaseHas('affiliate_commissions',['order_id'=>$order->id,'beneficiary_id'=>$l1->id,'level_no'=>1,'rate_bps'=>1000,'eligible_spend_minor'=>90_000,'reward_coins'=>6300,'status'=>'pending']);
        $this->assertDatabaseHas('affiliate_commissions',['order_id'=>$order->id,'beneficiary_id'=>$l2->id,'level_no'=>2,'rate_bps'=>900,'reward_coins'=>5670]);
        $this->assertDatabaseHas('affiliate_commissions',['order_id'=>$order->id,'beneficiary_id'=>$l3->id,'level_no'=>3,'rate_bps'=>800,'reward_coins'=>5040]);
        $this->assertNotNull($order->fresh()->affiliate_accrued_at);
    }

    /** Verifies unpaid order does not accrue. */
    public function test_unpaid_order_does_not_accrue(): void
    {
        $parent=User::factory()->create(); $buyer=User::factory()->create();
        $account=app(EnrollAffiliate::class)->execute($parent,'test'); app(AttachReferrer::class)->execute($buyer,$account->referral_code);
        $order=$this->makeOrder($buyer,PaymentStatus::Pending,100_000,0,0);
        $this->assertCount(0,app(AccrueAffiliateCommissions::class)->execute($order));
        $this->assertDatabaseCount('affiliate_commissions',0);
        $this->assertNull($order->fresh()->affiliate_accrued_at);
    }

    /** Verifies mature commission credits wallet exactly once. */
    public function test_mature_commission_credits_wallet_exactly_once(): void
    {
        Carbon::setTestNow('2026-08-08 10:00:00');
        config(['vsn.affiliate.hold_days'=>0]);
        $parent=User::factory()->create(); $buyer=User::factory()->create();
        $account=app(EnrollAffiliate::class)->execute($parent,'test'); app(AttachReferrer::class)->execute($buyer,$account->referral_code);
        $order=$this->makeOrder($buyer,PaymentStatus::Paid,100_000,0,0);
        app(AccrueAffiliateCommissions::class)->execute($order);

        $this->assertSame(1,app(MatureAffiliateCommissions::class)->execute());
        $this->assertSame(1,app(CreditAvailableAffiliateCommissions::class)->execute());
        $this->assertSame(0,app(CreditAvailableAffiliateCommissions::class)->execute());
        $this->assertDatabaseHas('wallets',['user_id'=>$parent->id,'balance_coins'=>7000]);
        $this->assertDatabaseHas('wallet_transactions',['type'=>'affiliate_commission','reference_type'=>'affiliate_commission']);
        $this->assertDatabaseHas('affiliate_commissions',['beneficiary_id'=>$parent->id,'status'=>'credited']);
        Carbon::setTestNow();
    }

    /** Verifies refund reversal uses compensating ledger and can record recovery debt. */
    public function test_refund_reversal_uses_compensating_ledger_and_can_record_recovery_debt(): void
    {
        config(['vsn.affiliate.hold_days'=>0]);
        $parent=User::factory()->create(); $buyer=User::factory()->create();
        $account=app(EnrollAffiliate::class)->execute($parent,'test'); app(AttachReferrer::class)->execute($buyer,$account->referral_code);
        $order=$this->makeOrder($buyer,PaymentStatus::Paid,100_000,0,0);
        app(AccrueAffiliateCommissions::class)->execute($order);
        app(MatureAffiliateCommissions::class)->execute(); app(CreditAvailableAffiliateCommissions::class)->execute();

        // Spend most of the credited reward first; a later chargeback still has to reverse the liability.
        app(WalletService::class)->debit($parent,6500,WalletTransactionType::Gift,'test-spend-before-chargeback','test','1');
        $this->assertDatabaseHas('wallets',['user_id'=>$parent->id,'balance_coins'=>500]);

        $this->assertSame(1,app(ReverseOrderAffiliateCommissions::class)->execute($order,'chargeback'));
        $this->assertSame(0,app(ReverseOrderAffiliateCommissions::class)->execute($order,'chargeback'));
        $this->assertDatabaseHas('wallets',['user_id'=>$parent->id,'balance_coins'=>-6500]);
        $this->assertDatabaseHas('affiliate_commissions',['beneficiary_id'=>$parent->id,'status'=>'reversed']);
        $this->assertDatabaseHas('wallet_transactions',['type'=>'reversal','reference_type'=>'order','reference_id'=>$order->public_id]);
    }

    /** Verifies dashboard returns actual network level counts. */
    public function test_dashboard_returns_actual_network_level_counts(): void
    {
        $root=User::factory()->create(); $l1a=User::factory()->create(); $l1b=User::factory()->create(); $l2=User::factory()->create();
        $enroll=app(EnrollAffiliate::class); $attach=app(AttachReferrer::class);
        $rootAccount=$enroll->execute($root,'test');
        $attach->execute($l1a,$rootAccount->referral_code); $attach->execute($l1b,$rootAccount->referral_code);
        $l1Account=$enroll->execute($l1a,'test'); $attach->execute($l2,$l1Account->referral_code);
        Sanctum::actingAs($root);

        $this->getJson('/api/v1/affiliate')->assertOk()
            ->assertJsonPath('data.metrics.totalNetwork',3)
            ->assertJsonPath('data.levels.0.members',2)
            ->assertJsonPath('data.levels.1.members',1)
            ->assertJsonPath('data.levels.8.rate',2.5)
            ->assertJsonPath('data.levels.9.rate',2);
    }


    /** Verifies invalid registration referral rolls back user creation. */
    public function test_invalid_registration_referral_rolls_back_user_creation(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name'=>'Invalid Referral','email'=>'invalid-ref@example.test','password'=>'StrongPass123','password_confirmation'=>'StrongPass123','referral_code'=>'NXDOESNOTEXIST',
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('users',['email'=>'invalid-ref@example.test']);
    }

    /** Verifies suspended affiliate does not receive mature wallet credit. */
    public function test_suspended_affiliate_does_not_receive_mature_wallet_credit(): void
    {
        config(['vsn.affiliate.hold_days'=>0]);
        $parent=User::factory()->create(); $buyer=User::factory()->create();
        $account=app(EnrollAffiliate::class)->execute($parent,'test'); app(AttachReferrer::class)->execute($buyer,$account->referral_code);
        $order=$this->makeOrder($buyer,PaymentStatus::Paid,100_000,0,0);
        app(AccrueAffiliateCommissions::class)->execute($order);
        app(MatureAffiliateCommissions::class)->execute();
        $account->update(['status'=>'suspended','suspended_at'=>now()]);

        $this->assertSame(0,app(CreditAvailableAffiliateCommissions::class)->execute());
        $this->assertDatabaseMissing('wallets',['user_id'=>$parent->id,'balance_coins'=>7000]);
        $this->assertDatabaseHas('affiliate_commissions',['beneficiary_id'=>$parent->id,'status'=>'available']);
    }

    /** Handles make order for the affiliate api test workflow. */
    private function makeOrder(User $buyer, PaymentStatus $paymentStatus, int $subtotalMinor, int $discountMinor, int $shippingMinor): Order
    {
        $cart=Cart::create(['public_id'=>(string)Str::ulid(),'user_id'=>$buyer->id,'status'=>CartStatus::Converted,'currency'=>'PKR']);
        $session=CheckoutSession::create([
            'public_id'=>(string)Str::ulid(),'user_id'=>$buyer->id,'cart_id'=>$cart->id,'idempotency_key'=>'affiliate-checkout-'.Str::uuid(),
            'status'=>CheckoutStatus::Converted,'currency'=>'PKR','address_snapshot'=>['recipient_name'=>$buyer->name,'phone'=>'03000000000','line1'=>'Test','city'=>'Lahore','country_code'=>'PK'],
            'shipping_method'=>'standard','payment_method'=>'card','subtotal_minor'=>$subtotalMinor,'shipping_minor'=>$shippingMinor,'discount_minor'=>$discountMinor,
            'coin_redemption_coins'=>0,'coin_redemption_minor'=>0,'total_minor'=>max(0,$subtotalMinor+$shippingMinor-$discountMinor),'expires_at'=>now()->addMinutes(15),'converted_at'=>now(),
        ]);
        return Order::create([
            'public_id'=>(string)Str::ulid(),'user_id'=>$buyer->id,'checkout_session_id'=>$session->id,'status'=>OrderStatus::Confirmed,'payment_status'=>$paymentStatus,'payment_method'=>'card','currency'=>'PKR',
            'subtotal_minor'=>$subtotalMinor,'shipping_minor'=>$shippingMinor,'discount_minor'=>$discountMinor,'coin_redemption_coins'=>0,'coin_redemption_minor'=>0,'total_minor'=>max(0,$subtotalMinor+$shippingMinor-$discountMinor),'placed_at'=>now(),
        ]);
    }
}
