<?php
namespace Tests\Feature;

use App\Domain\Kyc\Providers\HttpKycProvider;
use App\Domain\Notifications\Providers\ResendEmailProvider;
use App\Domain\Payments\Gateways\StripePaymentGateway;
use App\Domain\Payments\Gateways\StripePaymentVaultProvider;
use App\Domain\Security\Providers\TwilioSmsProvider;
use App\Domain\Shipping\Providers\HttpShippingProvider;
use App\Enums\ShipmentStatus;
use App\Models\PaymentIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Defines the ProviderIntegrationTest class and its project responsibilities. */
class ProviderIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** Verifies stripe webhook signature and event mapping are verified. */
    public function test_stripe_webhook_signature_and_event_mapping_are_verified(): void
    {
        $secret='whsec_test_12345678901234567890'; $timestamp=time();
        $payload=json_encode(['id'=>'evt_1','type'=>'payment_intent.succeeded','created'=>$timestamp,'data'=>['object'=>['id'=>'pi_1','amount'=>1200,'amount_received'=>1200,'currency'=>'usd','metadata'=>['vsn_payment_intent_id'=>'01TEST']]]],JSON_THROW_ON_ERROR);
        $signature=hash_hmac('sha256',$timestamp.'.'.$payload,$secret);
        $gateway=new StripePaymentGateway('sk_test_123','pk_test_123',$secret,'https://api.stripe.test',300);
        $verified=$gateway->verifyWebhook($payload,['Stripe-Signature'=>"t={$timestamp},v1={$signature}"]);
        $this->assertSame('payment.paid',$verified->eventType);
        $this->assertSame(1200,$verified->amountMinor);
    }

    /** Verifies stripe rejects invalid webhook signature. */
    public function test_stripe_rejects_invalid_webhook_signature(): void
    {
        $this->expectException(\App\Domain\Payments\Exceptions\InvalidWebhookSignature::class);
        $gateway=new StripePaymentGateway('sk_test_123','pk_test_123','whsec_test_12345678901234567890');
        $gateway->verifyWebhook('{"id":"evt"}',['Stripe-Signature'=>'t='.time().',v1=bad']);
    }

    /** Verifies stripe vault reads only provider tokenized card metadata. */
    public function test_stripe_vault_reads_only_provider_tokenized_card_metadata(): void
    {
        Http::fake(['https://api.stripe.test/v1/payment_methods/pm_test'=>Http::response(['id'=>'pm_test','customer'=>'cus_1','type'=>'card','card'=>['brand'=>'visa','last4'=>'4242','exp_month'=>12,'exp_year'=>2030,'fingerprint'=>'fp_1'],'billing_details'=>['name'=>'Test User']],200)]);
        $vault=new StripePaymentVaultProvider('sk_test_123','pk_test_123','https://api.stripe.test');
        $info=$vault->inspectToken('pm_test');
        $this->assertSame('4242',$info->last4);$this->assertSame('visa',$info->brand);$this->assertSame('cus_1',$info->metadata['stripe_customer_id']);
    }

    /** Verifies twilio adapter uses rest api and requires provider message id. */
    public function test_twilio_adapter_uses_rest_api_and_requires_provider_message_id(): void
    {
        Http::fake(['https://api.twilio.test/*'=>Http::response(['sid'=>'SM123'],201)]);
        $provider=new TwilioSmsProvider('AC123','token','+15551234567',null,'https://api.twilio.test');
        $provider->send('+15557654321','Your verification code is 123456');
        Http::assertSent(/** Inline callback for this operation. */ fn($r)=>str_contains($r->url(),'/Messages.json')&&$r['To']==='+15557654321');
    }

    /** Verifies resend adapter sends with idempotency key. */
    public function test_resend_adapter_sends_with_idempotency_key(): void
    {
        Http::fake(['https://api.resend.test/emails'=>Http::response(['id'=>'email_1'],200)]);
        $provider=new ResendEmailProvider('re_test_123','VSN <no-reply@example.com>','https://api.resend.test');
        $provider->send('buyer@example.com','Order shipped','Text','<p>Text</p>','notification-1');
        Http::assertSent(/** Inline callback for this operation. */ fn($r)=>$r->url()==='https://api.resend.test/emails'&&$r->hasHeader('Idempotency-Key','notification-1'));
    }

    /** Verifies generic courier requires signed webhook and maps status. */
    public function test_generic_courier_requires_signed_webhook_and_maps_status(): void
    {
        $secret='courier-secret-123456789012345';$payload=json_encode(['eventId'=>'ev_1','shipmentId'=>'ship_1','trackingNumber'=>'TRK1','status'=>'out_for_delivery','occurredAt'=>now()->toIso8601String()],JSON_THROW_ON_ERROR);
        $signature='sha256='.hash_hmac('sha256',$payload,$secret);
        $provider=new HttpShippingProvider('courier_http','https://courier.test','token',$secret);
        $event=$provider->verifyWebhook($payload,['X-VSN-Signature'=>$signature]);
        $this->assertSame(ShipmentStatus::OutForDelivery,$event->status);
    }

    /** Verifies generic kyc probe is fail closed without live configuration. */
    public function test_generic_kyc_probe_is_fail_closed_without_live_configuration(): void
    {
        $provider=new HttpKycProvider('','', '');
        $probe=$provider->probe();
        $this->assertFalse($probe->healthy);$this->assertFalse($probe->productionReady);
    }

    /** Verifies provider runtime admin routes are protected from customers. */
    public function test_provider_runtime_admin_routes_are_protected_from_customers(): void
    {
        $user=\App\Models\User::factory()->create(['role'=>\App\Enums\UserRole::Customer]);
        $this->actingAs($user)->getJson('/api/v1/admin/system/providers')->assertForbidden();
        $this->actingAs($user)->postJson('/api/v1/admin/system/providers/probe')->assertForbidden();
    }

    /** Verifies payment method endpoint prohibits raw card data. */
    public function test_payment_method_endpoint_prohibits_raw_card_data(): void
    {
        $user=\App\Models\User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/payment-methods',['provider'=>'stripe','providerToken'=>'pm_test','cardNumber'=>'4242424242424242'])->assertUnprocessable();
    }

    /** Verifies live provider secrets are never returned by admin status api. */
    public function test_live_provider_secrets_are_never_returned_by_admin_status_api(): void
    {
        config(['vsn.payments.methods.card.enabled'=>false,'vsn.security.sms_provider'=>'sandbox','vsn.notifications.email_provider'=>'laravel_mail','vsn.kyc.provider'=>'manual','vsn.payments.providers.stripe.secret_key'=>'sk_live_secret_should_not_leak']);
        $admin=\App\Models\User::factory()->create(['role'=>\App\Enums\UserRole::Admin]);
        $json=$this->actingAs($admin)->getJson('/api/v1/admin/system/providers')->assertOk()->getContent();
        $this->assertStringNotContainsString('sk_live_secret_should_not_leak',$json);
    }
}
