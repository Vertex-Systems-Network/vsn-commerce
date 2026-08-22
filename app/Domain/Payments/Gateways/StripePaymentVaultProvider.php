<?php
namespace App\Domain\Payments\Gateways;
use App\Domain\Payments\Contracts\PaymentVaultProvider;
use App\Domain\Payments\Data\VaultedPaymentMethodData;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Domain\Providers\Contracts\ProviderProbe;
use App\Domain\Providers\Data\ProviderProbeResult;
use App\Models\PaymentProviderCustomer;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
/** Defines the StripePaymentVaultProvider class and its project responsibilities. */
final class StripePaymentVaultProvider implements PaymentVaultProvider,ProviderProbe
{
    /** Initializes the StripePaymentVaultProvider instance and its dependencies. */
    public function __construct(private readonly string $secretKey,private readonly string $publishableKey,private readonly string $apiBase='https://api.stripe.com'){}
    /** Handles code for the stripe payment vault provider workflow. */
    public function code():string{return 'stripe';}
    /** Handles provider type for the stripe payment vault provider workflow. */
    public function providerType():string{return 'payment_vault';}
    /** Handles provider code for the stripe payment vault provider workflow. */
    public function providerCode():string{return 'stripe';}
    /** Handles inspect token for the stripe payment vault provider workflow. */
    public function inspectToken(string $providerToken):VaultedPaymentMethodData
    {
        if(!str_starts_with($providerToken,'pm_'))throw new PaymentException('Stripe payment-method token is invalid.','providerToken');
        $r=$this->request()->get($this->apiBase.'/v1/payment_methods/'.rawurlencode($providerToken));
        if(!$r->successful())throw new PaymentException('Stripe could not verify this payment method.','providerToken');
        $d=$r->json();$card=$d['card']??null;if(!is_array($card))throw new PaymentException('Only Stripe card payment methods can be saved here.','providerToken');
        return new VaultedPaymentMethodData($providerToken,(string)($card['brand']??'card'),(string)($card['last4']??''),(int)($card['exp_month']??0),(int)($card['exp_year']??0),(string)($card['fingerprint']??$providerToken),$d['billing_details']['name']??null,['stripe_payment_method_id'=>$providerToken,'stripe_customer_id'=>$d['customer']??null]);
    }
    /** Handles detach for the stripe payment vault provider workflow. */
    public function detach(string $providerToken):void
    {
        if(!str_starts_with($providerToken,'pm_'))return;
        $r=$this->request()->asForm()->post($this->apiBase.'/v1/payment_methods/'.rawurlencode($providerToken).'/detach');
        if(!$r->successful() && $r->status()!==404)throw new PaymentException('Stripe could not detach the payment method.');
    }
    /** Handles create setup intent for the stripe payment vault provider workflow. */
    public function createSetupIntent(User $user):array
    {
        $customerId=$this->ensureCustomer($user);
        $r=$this->request()->withHeader('Idempotency-Key','vsn-setup-'.$user->id.'-'.Str::ulid())->asForm()->post($this->apiBase.'/v1/setup_intents',['usage'=>'off_session','payment_method_types[]'=>'card','customer'=>$customerId,'metadata[vsn_user_id]'=>$user->id]);
        if(!$r->successful())throw new PaymentException('Stripe could not initialize card setup.');$d=$r->json();
        return ['provider'=>'stripe','setupIntentId'=>$d['id']??null,'clientSecret'=>$d['client_secret']??null,'publishableKey'=>$this->publishableKey,'customerReference'=>substr($customerId,0,7).'…'];
    }
    /** Handles ensure customer for the stripe payment vault provider workflow. */
    private function ensureCustomer(User $user):string
    {
        $existing=PaymentProviderCustomer::query()->where('user_id',$user->id)->where('provider','stripe')->first();if($existing)return $existing->provider_customer_id_cipher;
        $r=$this->request()->withHeader('Idempotency-Key','vsn-customer-'.$user->id)->asForm()->post($this->apiBase.'/v1/customers',['email'=>$user->email,'name'=>$user->name,'metadata[vsn_user_id]'=>$user->id]);
        if(!$r->successful())throw new PaymentException('Stripe customer vault initialization failed.');$customer=(string)($r->json('id')??'');if(!str_starts_with($customer,'cus_'))throw new PaymentException('Stripe returned an invalid customer reference.');
        $row=PaymentProviderCustomer::query()->firstOrCreate(['user_id'=>$user->id,'provider'=>'stripe'],['public_id'=>(string)Str::ulid(),'provider_customer_id_cipher'=>$customer]);return $row->provider_customer_id_cipher;
    }
    /** Handles probe for the stripe payment vault provider workflow. */
    public function probe():ProviderProbeResult
    {
        if(!str_starts_with($this->secretKey,'sk_')||!str_starts_with($this->publishableKey,'pk_'))return new ProviderProbeResult(false,false,'Stripe vault credentials are incomplete.');
        $r=$this->request()->timeout(8)->get($this->apiBase.'/v1/account');$healthy=$r->successful();$live=str_starts_with($this->secretKey,'sk_live_')&&str_starts_with($this->publishableKey,'pk_live_');$ready=$healthy&&$live;return new ProviderProbeResult($healthy,$ready,$ready?'Stripe live vault probe succeeded.':($healthy?'Stripe vault API is reachable, but live keys are required for production.':'Stripe vault API probe failed.'),['httpStatus'=>$r->status(),'liveCredentials'=>$live]);
    }
    /** Handles request for the stripe payment vault provider workflow. */
    private function request(){return Http::acceptJson()->withBasicAuth($this->secretKey,'')->timeout(15)->retry(2,250,throw:false);}
}
