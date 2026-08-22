<?php

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Data\GatewayIntentResult;
use App\Domain\Payments\Data\GatewayRefundResult;
use App\Domain\Payments\Data\VerifiedWebhook;
use App\Domain\Payments\Exceptions\InvalidWebhookSignature;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Domain\Providers\Contracts\ProviderProbe;
use App\Domain\Providers\Data\ProviderProbeResult;
use App\Models\PaymentIntent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** Defines the StripePaymentGateway class and its project responsibilities. */
final class StripePaymentGateway implements PaymentGateway, ProviderProbe
{
    /** Initializes the StripePaymentGateway instance and its dependencies. */
    public function __construct(
        private readonly string $secretKey,
        private readonly string $publishableKey,
        private readonly string $webhookSecret,
        private readonly string $apiBase = 'https://api.stripe.com',
        private readonly int $webhookToleranceSeconds = 300,
    ) {}

    /** Handles code for the stripe payment gateway workflow. */
    public function code(): string { return 'stripe'; }
    /** Handles provider type for the stripe payment gateway workflow. */
    public function providerType(): string { return 'payment'; }
    /** Handles provider code for the stripe payment gateway workflow. */
    public function providerCode(): string { return 'stripe'; }

    /** Handles create intent for the stripe payment gateway workflow. */
    public function createIntent(PaymentIntent $intent): GatewayIntentResult
    {
        $this->assertConfigured();
        $payload = [
            'amount' => $intent->amount_minor,
            'currency' => strtolower($intent->currency),
            'description' => 'VSN Ecommerce '.$intent->purpose.' '.$intent->public_id,
            'metadata' => [
                'vsn_payment_intent_id' => $intent->public_id,
                'vsn_purpose' => (string) $intent->purpose,
                'vsn_reference_id' => (string) ($intent->reference_id ?? ''),
            ],
            'automatic_payment_methods' => ['enabled' => 'true'],
        ];

        if ($intent->savedPaymentMethod?->provider_token_cipher) {
            $payload['payment_method'] = $intent->savedPaymentMethod->provider_token_cipher;
            $payload['confirm'] = 'true';
            $payload['return_url'] = rtrim((string) config('vsn.frontend_url'), '/').'/checkout?payment='.$intent->public_id;
            $payload['automatic_payment_methods']['allow_redirects'] = 'always';
        }

        $response = $this->request()
            ->withHeader('Idempotency-Key', 'vsn-intent-'.$intent->public_id)
            ->asForm()
            ->post($this->apiBase.'/v1/payment_intents', $this->flatten($payload));

        if (! $response->successful()) {
            throw new PaymentException('Stripe could not create the payment intent: '.$this->stripeError($response->json()), 'paymentMethod');
        }

        $data = $response->json();
        $providerId = (string) ($data['id'] ?? '');
        $clientSecret = (string) ($data['client_secret'] ?? '');
        if ($providerId === '' || $clientSecret === '') throw new PaymentException('Stripe returned an incomplete PaymentIntent response.');

        return new GatewayIntentResult(
            $providerId,
            [
                'type' => 'stripe_payment_intent',
                'clientSecret' => $clientSecret,
                'publishableKey' => $this->publishableKey,
                'status' => (string) ($data['status'] ?? 'requires_payment_method'),
            ],
            ['stripe_status' => $data['status'] ?? null],
        );
    }

    /** Handles refund for the stripe payment gateway workflow. */
    public function refund(PaymentIntent $intent, int $amountMinor, string $idempotencyKey): GatewayRefundResult
    {
        $this->assertConfigured();
        if (! $intent->provider_payment_id) throw new PaymentException('Stripe PaymentIntent reference is missing.');

        $response = $this->request()
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->asForm()
            ->post($this->apiBase.'/v1/refunds', [
                'payment_intent' => $intent->provider_payment_id,
                'amount' => $amountMinor,
                'metadata[refund_reference]' => $idempotencyKey,
                'metadata[vsn_payment_intent_id]' => $intent->public_id,
            ]);
        if (! $response->successful()) throw new PaymentException('Stripe refund failed: '.$this->stripeError($response->json()));
        $data = $response->json();
        return new GatewayRefundResult(
            (string) ($data['id'] ?? ''),
            in_array(($data['status'] ?? null), ['succeeded','pending'], true) ? (string) $data['status'] : 'failed',
            ['stripe_refund_status'=>$data['status'] ?? null,'stripe_payment_intent'=>$data['payment_intent'] ?? $intent->provider_payment_id],
        );
    }

    /** Handles verify webhook for the stripe payment gateway workflow. */
    public function verifyWebhook(string $rawPayload, array $headers): VerifiedWebhook
    {
        $this->assertConfigured();
        $header = $this->firstHeader($headers, 'stripe-signature');
        if ($header === null || ! $this->validSignature($rawPayload, $header)) {
            throw new InvalidWebhookSignature('Stripe webhook signature is invalid.');
        }

        $payload = json_decode($rawPayload, true);
        if (! is_array($payload)) throw new InvalidWebhookSignature('Stripe webhook payload is invalid JSON.');
        if (app()->isProduction() && ($payload['livemode'] ?? false) !== true) throw new InvalidWebhookSignature('Stripe test-mode webhook cannot be accepted in production.');
        $object = $payload['data']['object'] ?? [];
        if (! is_array($object)) $object = [];
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $publicId = (string) ($metadata['vsn_payment_intent_id'] ?? '');
        if ($publicId === '') throw new PaymentException('Stripe webhook is missing VSN payment metadata.');

        if ((string) ($payload['id'] ?? '') === '') throw new PaymentException('Stripe webhook event ID is missing.');
        $mapped = match ((string) ($payload['type'] ?? '')) {
            'payment_intent.succeeded' => 'payment.paid',
            'payment_intent.amount_capturable_updated' => 'payment.authorized',
            'payment_intent.payment_failed', 'payment_intent.canceled' => 'payment.failed',
            default => 'payment.ignored',
        };
        $amount = match ($mapped) {
            'payment.paid' => (int) ($object['amount_received'] ?? $object['amount'] ?? 0),
            'payment.authorized' => (int) ($object['amount_capturable'] ?? $object['amount'] ?? 0),
            default => (int) ($object['amount'] ?? 0),
        };

        return new VerifiedWebhook(
            (string) ($payload['id'] ?? ''),
            $mapped,
            $publicId,
            (string) ($object['id'] ?? ''),
            isset($object['latest_charge']) ? (string) $object['latest_charge'] : null,
            $amount,
            strtoupper((string) ($object['currency'] ?? '')),
            CarbonImmutable::createFromTimestampUTC((int) ($payload['created'] ?? time())),
            $payload,
        );
    }

    /** Handles retrieve intent for the stripe payment gateway workflow. */
    public function retrieveIntent(string $providerPaymentId): array
    {
        $this->assertConfigured();
        $response=$this->request()->get($this->apiBase.'/v1/payment_intents/'.rawurlencode($providerPaymentId));
        if(! $response->successful()) throw new PaymentException('Stripe PaymentIntent lookup failed: '.$this->stripeError($response->json()));
        return $response->json();
    }

    /** Handles lookup intent for the stripe payment gateway workflow. */
    public function lookupIntent(PaymentIntent $intent): array
    {
        if (! $intent->provider_payment_id) throw new PaymentException('Stripe PaymentIntent reference is missing.');
        $d = $this->retrieveIntent($intent->provider_payment_id);
        return [
            'providerPaymentId' => $d['id'] ?? null,
            'status' => (string) ($d['status'] ?? 'unknown'),
            'amountMinor' => (int) ($d['amount'] ?? 0),
            'currency' => strtoupper((string) ($d['currency'] ?? '')),
            'raw' => $d,
        ];
    }

    /** Handles probe for the stripe payment gateway workflow. */
    public function probe(): ProviderProbeResult
    {
        if (! $this->configured()) return new ProviderProbeResult(false,false,'Stripe credentials are incomplete.');
        $response=$this->request()->timeout(8)->get($this->apiBase.'/v1/account');
        if(! $response->successful()) return new ProviderProbeResult(false,false,'Stripe account probe failed.',['httpStatus'=>$response->status()]);
        $json=$response->json();$liveKeys=str_starts_with($this->secretKey,'sk_live_')&&str_starts_with($this->publishableKey,'pk_live_');$charges=(bool)($json['charges_enabled']??false);$productionReady=$liveKeys&&$charges;
        return new ProviderProbeResult(true,$productionReady,$productionReady?'Stripe live account probe succeeded.':'Stripe API is reachable, but live keys and enabled charges are required for production.',[
            'accountId'=>$json['id'] ?? null,
            'liveCredentials'=>$liveKeys,
            'chargesEnabled'=>$charges,
            'payoutsEnabled'=>(bool)($json['payouts_enabled'] ?? false),
        ]);
    }

    /** Handles request for the stripe payment gateway workflow. */
    private function request(): PendingRequest
    {
        return Http::acceptJson()->withBasicAuth($this->secretKey, '')->timeout(15)->retry(2, 250, throw:false);
    }
    /** Handles configured for the stripe payment gateway workflow. */
    private function configured():bool{return str_starts_with($this->secretKey,'sk_') && str_starts_with($this->publishableKey,'pk_') && str_starts_with($this->webhookSecret,'whsec_');}
    /** Handles assert configured for the stripe payment gateway workflow. */
    private function assertConfigured():void{if(! $this->configured())throw new PaymentException('Stripe provider credentials are incomplete.');}
    /** Handles stripe error for the stripe payment gateway workflow. */
    private function stripeError(array $json):string{return (string)($json['error']['message'] ?? 'provider request failed');}
    /** Handles first header for the stripe payment gateway workflow. */
    private function firstHeader(array $headers,string $name):?string{foreach($headers as $key=>$values)if(strtolower((string)$key)===$name)return is_array($values)?($values[0]??null):(string)$values;return null;}
    /** Handles valid signature for the stripe payment gateway workflow. */
    private function validSignature(string $payload,string $header):bool
    {
        $parts=[];foreach(explode(',',$header) as $piece){[$k,$v]=array_pad(explode('=',trim($piece),2),2,null);if($k&&$v!==null)$parts[$k][]=$v;}
        $timestamp=(int)($parts['t'][0]??0);$signatures=$parts['v1']??[];
        if($timestamp<=0 || abs(time()-$timestamp)>$this->webhookToleranceSeconds || $signatures===[])return false;
        $expected=hash_hmac('sha256',$timestamp.'.'.$payload,$this->webhookSecret);
        foreach($signatures as $signature)if(hash_equals($expected,(string)$signature))return true;
        return false;
    }
    /** Handles flatten for the stripe payment gateway workflow. */
    private function flatten(array $data,string $prefix=''):array
    {
        $out=[];foreach($data as $key=>$value){$field=$prefix===''?(string)$key:$prefix.'['.$key.']';if(is_array($value))$out+=$this->flatten($value,$field);elseif(is_bool($value))$out[$field]=$value?'true':'false';else$out[$field]=$value;}return $out;
    }
}
