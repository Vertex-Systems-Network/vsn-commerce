<?php

namespace Tests\Feature;

use App\Domain\Payments\Gateways\SandboxPaymentVaultProvider;
use App\Enums\ProductStatus;
use App\Models\Address;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SavedPaymentMethod;
use App\Models\SecurityStepUpSession;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Defines the SavedPaymentMethodApiTest class and its project responsibilities. */
class SavedPaymentMethodApiTest extends TestCase
{
    use RefreshDatabase;

    /** Updates up. */
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'vsn.payments.providers.sandbox.vault_secret' => 'test-vault-secret',
            'vsn.payments.methods.card.enabled' => true,
            'vsn.payments.methods.card.provider' => 'sandbox',
        ]);
    }

    /** Verifies step up token is hashed and bound to device. */
    public function test_step_up_token_is_hashed_and_bound_to_device(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);
        $response = $this->withHeader('X-Device-Id', 'device-a')->postJson('/api/v1/security/step-up', [
            'password'=>'secret-pass', 'purpose'=>'payment_methods',
        ])->assertOk();

        $token = $response->json('data.token');
        $row = SecurityStepUpSession::firstOrFail();
        $this->assertNotSame($token, $row->token_hash);
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertSame(hash('sha256', 'device-a'), $row->device_hash);
    }

    /** Verifies wrong password cannot create step up session. */
    public function test_wrong_password_cannot_create_step_up_session(): void
    {
        Sanctum::actingAs($this->user());
        $this->postJson('/api/v1/security/step-up', ['password'=>'wrong','purpose'=>'payment_methods'])->assertUnprocessable();
        $this->assertDatabaseCount('security_step_up_sessions', 0);
    }

    /** Verifies raw card and cvc fields are explicitly prohibited. */
    public function test_raw_card_and_cvc_fields_are_explicitly_prohibited(): void
    {
        Sanctum::actingAs($this->user());
        $this->postJson('/api/v1/payment-methods', [
            'provider'=>'sandbox','providerToken'=>'token','cardNumber'=>'4242424242424242','cvc'=>'123',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('saved_payment_methods', 0);
    }

    /** Verifies sandbox setup is disabled by default. */
    public function test_sandbox_setup_is_disabled_by_default(): void
    {
        Sanctum::actingAs($this->user());
        config(['vsn.payments.providers.sandbox.vault_simulator_enabled'=>false]);
        $this->postJson('/api/v1/payment-methods/sandbox/setup', ['brand'=>'visa','last4'=>'4242','expMonth'=>12,'expYear'=>2030])->assertNotFound();
    }

    /** Verifies provider token is encrypted and never returned by resource. */
    public function test_provider_token_is_encrypted_and_never_returned_by_resource(): void
    {
        $user = $this->user(); Sanctum::actingAs($user);
        $step = $this->stepUp($user, 'device-a');
        $providerToken = (new SandboxPaymentVaultProvider('test-vault-secret'))->issueTestToken('visa','4242',12,2030,'Test User',$user->id);

        $response = $this->withHeaders(['X-Device-Id'=>'device-a','X-Step-Up-Token'=>$step])->postJson('/api/v1/payment-methods', [
            'provider'=>'sandbox','providerToken'=>$providerToken,'makeDefault'=>true,
        ])->assertCreated()->assertJsonPath('data.last4','4242');

        $this->assertNull($response->json('data.providerToken'));
        $raw = DB::table('saved_payment_methods')->value('provider_token_cipher');
        $this->assertNotSame($providerToken, $raw);
        $this->assertSame($providerToken, SavedPaymentMethod::firstOrFail()->provider_token_cipher);
    }

    /** Verifies another user cannot change or revoke saved method. */
    public function test_another_user_cannot_change_or_revoke_saved_method(): void
    {
        $owner = $this->user();
        $other = $this->user('other@example.test');
        $method = $this->method($owner, '1111');
        Sanctum::actingAs($other);
        $step = $this->stepUp($other, 'device-b');

        $this->withHeaders(['X-Device-Id'=>'device-b','X-Step-Up-Token'=>$step])->postJson("/api/v1/payment-methods/{$method->public_id}/default", [])->assertNotFound();
        $this->withHeaders(['X-Device-Id'=>'device-b','X-Step-Up-Token'=>$step])->deleteJson("/api/v1/payment-methods/{$method->public_id}")->assertNotFound();
    }

    /** Verifies expired saved method is not exposed as checkout option. */
    public function test_expired_saved_method_is_not_exposed_as_checkout_option(): void
    {
        [$user,$address] = $this->customer(); Sanctum::actingAs($user);
        $this->method($user, '2222', 1, 2020);
        $this->getJson("/api/v1/checkout/options?addressId={$address->id}")->assertOk()->assertJsonCount(0, 'data.savedPaymentMethods');
    }

    /** Verifies checkout rejects other users saved method and accepts owners method. */
    public function test_checkout_rejects_other_users_saved_method_and_accepts_owners_method(): void
    {
        [$user,$address] = $this->customer();
        [$product] = $this->productWithStock();
        $other = $this->user('owner-two@example.test');
        $foreign = $this->method($other, '3333');
        $owned = $this->method($user, '4444');
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/cart/items', ['productId'=>$product->id,'quantity'=>1])->assertOk();
        $base=['addressId'=>$address->id,'shippingMethod'=>'standard','paymentMethod'=>'card'];

        $this->postJson('/api/v1/checkout/sessions', $base + ['savedPaymentMethodId'=>$foreign->public_id,'idempotencyKey'=>'saved-card-foreign-01'])->assertUnprocessable();
        $session = $this->postJson('/api/v1/checkout/sessions', $base + ['savedPaymentMethodId'=>$owned->public_id,'idempotencyKey'=>'saved-card-owned-001'])
            ->assertOk()->assertJsonPath('data.savedPaymentMethod.last4','4444')->json('data');

        $this->postJson("/api/v1/checkout/sessions/{$session['id']}/payments", ['idempotencyKey'=>'saved-card-payment-001'])
            ->assertOk()->assertJsonPath('data.savedPaymentMethod.last4','4444')->assertJsonPath('data.clientAction.type','saved_payment_method');
    }

    /** Handles step up for the saved payment method api test workflow. */
    private function stepUp(User $user, string $device): string
    {
        Sanctum::actingAs($user);
        return $this->withHeader('X-Device-Id', $device)->postJson('/api/v1/security/step-up', [
            'password'=>'secret-pass','purpose'=>'payment_methods',
        ])->assertOk()->json('data.token');
    }

    /** Handles user for the saved payment method api test workflow. */
    private function user(string $email='saved@example.test'): User
    {
        return User::factory()->create(['email'=>$email,'password'=>Hash::make('secret-pass')]);
    }

    /** Handles method for the saved payment method api test workflow. */
    private function method(User $user, string $last4, int $month=12, int $year=2030): SavedPaymentMethod
    {
        return SavedPaymentMethod::create([
            'public_id'=>(string) Str::ulid(),'user_id'=>$user->id,'provider'=>'sandbox','payment_method'=>'card',
            'provider_token_cipher'=>'sbx-test-token-'.$last4,'fingerprint_sha256'=>hash('sha256',$user->id.$last4),
            'brand'=>'visa','last4'=>$last4,'exp_month'=>$month,'exp_year'=>$year,'status'=>'active','is_default'=>true,'verified_at'=>now(),
        ]);
    }

    /** Handles customer for the saved payment method api test workflow. */
    private function customer(): array
    {
        $user=$this->user('customer-'.Str::lower(Str::random(6)).'@example.test');
        $address=Address::create(['user_id'=>$user->id,'label'=>'Home','recipient_name'=>$user->name,'phone'=>'03001234567','line1'=>'1 Test Street','city'=>'Lahore','state'=>'Punjab','postal_code'=>'54000','country_code'=>'PK','is_default'=>true]);
        return [$user,$address];
    }

    /** Handles product with stock for the saved payment method api test workflow. */
    private function productWithStock(): array
    {
        $vendor=Vendor::create(['name'=>'Vault Seller','slug'=>'vault-seller-'.Str::lower(Str::random(5)),'status'=>'active','commission_bps'=>1000]);
        $product=Product::create(['public_id'=>(string)Str::ulid(),'vendor_id'=>$vendor->id,'sku'=>'PM-'.Str::upper(Str::random(8)),'slug'=>'payment-method-product-'.Str::lower(Str::random(5)),'name'=>'Payment Method Product','status'=>ProductStatus::Published,'currency'=>'PKR','base_price_minor'=>100_000]);
        $variant=ProductVariant::create(['product_id'=>$product->id,'sku'=>$product->sku.'-A','name'=>'Default','price_minor'=>100_000,'is_default'=>true,'is_active'=>true]);
        $warehouse=Warehouse::create(['code'=>'WH-'.Str::upper(Str::random(6)),'name'=>'Vault Test Warehouse']);
        $inventory=Inventory::create(['warehouse_id'=>$warehouse->id,'product_variant_id'=>$variant->id,'on_hand'=>5,'reserved'=>0,'safety_stock'=>0]);
        return [$product,$variant,$inventory,$vendor];
    }
}
