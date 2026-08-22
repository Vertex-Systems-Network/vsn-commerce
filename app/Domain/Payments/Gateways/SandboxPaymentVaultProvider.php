<?php
namespace App\Domain\Payments\Gateways;
use App\Domain\Payments\Contracts\PaymentVaultProvider;
use App\Domain\Payments\Data\VaultedPaymentMethodData;
use App\Domain\Payments\Exceptions\PaymentException;
use Illuminate\Support\Str;
/** Defines the SandboxPaymentVaultProvider class and its project responsibilities. */
class SandboxPaymentVaultProvider implements PaymentVaultProvider{
 /** Initializes the SandboxPaymentVaultProvider instance and its dependencies. */
 public function __construct(private readonly string $secret){}
 /** Handles code for the sandbox payment vault provider workflow. */
 public function code():string{return 'sandbox';}
 /** Handles issue test token for the sandbox payment vault provider workflow. */
 public function issueTestToken(string $brand,string $last4,int $expMonth,int $expYear,?string $holderName=null,?int $subjectUserId=null):string{
   if(app()->isProduction())throw new PaymentException('Sandbox payment vault is disabled in production.');
   $payload=['jti'=>(string)Str::ulid(),'brand'=>strtolower(trim($brand)),'last4'=>$last4,'exp_month'=>$expMonth,'exp_year'=>$expYear,'holder_name'=>$holderName,'fingerprint'=>(string)Str::uuid(),'subject_user_id'=>$subjectUserId];
   $encoded=rtrim(strtr(base64_encode(json_encode($payload,JSON_THROW_ON_ERROR)),'+/','-_'),'=');$sig=hash_hmac('sha256',$encoded,$this->secret);return 'sbx_pm_'.$encoded.'.'.$sig;
 }
 /** Handles inspect token for the sandbox payment vault provider workflow. */
 public function inspectToken(string $providerToken):VaultedPaymentMethodData{
   if(!str_starts_with($providerToken,'sbx_pm_'))throw new PaymentException('Invalid sandbox payment token.','providerToken');
   $parts=explode('.',substr($providerToken,7),2);if(count($parts)!==2)throw new PaymentException('Invalid sandbox payment token.','providerToken');[$encoded,$sig]=$parts;
   $expected=hash_hmac('sha256',$encoded,$this->secret);if(!hash_equals($expected,$sig))throw new PaymentException('Invalid sandbox payment token signature.','providerToken');
   $p=json_decode(base64_decode(strtr($encoded,'-_','+/')),true,512,JSON_THROW_ON_ERROR);
   return new VaultedPaymentMethodData($providerToken,(string)$p['brand'],(string)$p['last4'],(int)$p['exp_month'],(int)$p['exp_year'],(string)$p['fingerprint'],$p['holder_name']??null,['sandbox'=>true,'subject_user_id'=>$p['subject_user_id']??null]);
 }
 /** Handles detach for the sandbox payment vault provider workflow. */
 public function detach(string $providerToken):void{$this->inspectToken($providerToken);}
}
