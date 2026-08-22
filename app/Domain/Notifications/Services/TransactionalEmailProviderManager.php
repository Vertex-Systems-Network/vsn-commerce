<?php
namespace App\Domain\Notifications\Services;
use App\Domain\Notifications\Contracts\TransactionalEmailProvider;
use App\Domain\Notifications\Providers\LaravelMailEmailProvider;
use App\Domain\Notifications\Providers\ResendEmailProvider;
use RuntimeException;
/** Defines the TransactionalEmailProviderManager class and its project responsibilities. */
final class TransactionalEmailProviderManager
{
    /** Handles provider for the transactional email provider manager workflow. */
    public function provider():TransactionalEmailProvider
    {
        $code=(string)config('vsn.notifications.email_provider','laravel_mail');
        return match($code){
            'laravel_mail'=>new LaravelMailEmailProvider(),
            'resend'=>new ResendEmailProvider((string)config('vsn.notifications.providers.resend.api_key'),(string)config('vsn.notifications.providers.resend.from'),(string)config('vsn.notifications.providers.resend.api_base','https://api.resend.com')),
            default=>throw new RuntimeException("Transactional email provider [{$code}] is not registered."),
        };
    }
}
