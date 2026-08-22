<?php
namespace App\Domain\Security\Services;
use App\Domain\Security\Contracts\SmsProvider;
use App\Domain\Security\Providers\SandboxSmsProvider;
use App\Domain\Security\Providers\TwilioSmsProvider;
use RuntimeException;
/** Defines the SmsProviderManager class and its project responsibilities. */
class SmsProviderManager {
 /** Initializes the SmsProviderManager instance and its dependencies. */
 public function __construct(private readonly SandboxSmsProvider $sandbox) {}
 /** Handles provider for the sms provider manager workflow. */
 public function provider(): SmsProvider {
  $code=(string)config('vsn.security.sms_provider','sandbox');
  if($code==='sandbox'){if(app()->isProduction())throw new RuntimeException('Sandbox SMS provider is disabled in production.');return $this->sandbox;}
  if($code==='twilio')return new TwilioSmsProvider((string)config('vsn.security.providers.twilio.account_sid'),(string)config('vsn.security.providers.twilio.auth_token'),config('vsn.security.providers.twilio.from'),config('vsn.security.providers.twilio.messaging_service_sid'),(string)config('vsn.security.providers.twilio.api_base','https://api.twilio.com'));
  throw new RuntimeException("SMS provider [{$code}] is not registered.");
 }
}
