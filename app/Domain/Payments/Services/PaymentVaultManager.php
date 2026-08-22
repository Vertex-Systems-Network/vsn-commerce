<?php
namespace App\Domain\Payments\Services;
use App\Domain\Payments\Contracts\PaymentVaultProvider;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Domain\Payments\Gateways\SandboxPaymentVaultProvider;
use App\Domain\Payments\Gateways\StripePaymentVaultProvider;
/** Defines the PaymentVaultManager class and its project responsibilities. */
class PaymentVaultManager{
 /** Handles provider for the payment vault manager workflow. */
 public function provider(string $code):PaymentVaultProvider{
  if($code==='sandbox'&&app()->isProduction())throw new PaymentException('Sandbox payment vault is disabled in production.','provider');
  return match($code){'sandbox'=>new SandboxPaymentVaultProvider((string)config('vsn.payments.providers.sandbox.vault_secret')),'stripe'=>new StripePaymentVaultProvider((string)config('vsn.payments.providers.stripe.secret_key'),(string)config('vsn.payments.providers.stripe.publishable_key'),(string)config('vsn.payments.providers.stripe.api_base','https://api.stripe.com')),default=>throw new PaymentException("Payment vault provider [{$code}] is not registered.",'provider')};
 }
 /** Handles sandbox setup enabled for the payment vault manager workflow. */
 public function sandboxSetupEnabled():bool{return !app()->isProduction()&&(bool)config('vsn.payments.providers.sandbox.vault_simulator_enabled',false);}
}
