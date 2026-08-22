<?php
namespace App\Domain\Security\Providers;
use App\Domain\Security\Contracts\SmsProvider;
/** Defines the SandboxSmsProvider class and its project responsibilities. */
class SandboxSmsProvider implements SmsProvider {
    /** Handles name for the sandbox sms provider workflow. */
    public function name(): string { return 'sandbox'; }
    /** Handles send for the sandbox sms provider workflow. */
    public function send(string $phone, string $message): void {
        abort_unless(config('vsn.security.sandbox_sms_enabled', false) && app()->environment(['local','testing']), 503, 'SMS provider is not configured.');
        logger()->info('Sandbox SMS', ['phoneHash' => hash('sha256', $phone), 'message' => $message]);
    }
}
