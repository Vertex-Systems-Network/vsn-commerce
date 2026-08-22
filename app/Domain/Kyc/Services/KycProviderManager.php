<?php
namespace App\Domain\Kyc\Services;
use App\Domain\Kyc\Contracts\KycProvider;
use App\Domain\Kyc\Providers\HttpKycProvider;
use App\Domain\Kyc\Providers\ManualKycProvider;
use RuntimeException;
/** Defines the KycProviderManager class and its project responsibilities. */
final class KycProviderManager
{
    /** Handles provider for the kyc provider manager workflow. */
    public function provider():KycProvider
    {
        $code=(string)config('vsn.kyc.provider','manual');
        return match($code){
            'manual'=>app(ManualKycProvider::class),
            'kyc_http'=>new HttpKycProvider((string)config('vsn.kyc.providers.kyc_http.base_url'),(string)config('vsn.kyc.providers.kyc_http.api_token'),(string)config('vsn.kyc.providers.kyc_http.webhook_secret'),(string)config('vsn.kyc.providers.kyc_http.submit_path','/verifications'),(string)config('vsn.kyc.providers.kyc_http.health_path','/health')),
            default=>throw new RuntimeException("KYC provider [{$code}] is not registered."),
        };
    }
}
