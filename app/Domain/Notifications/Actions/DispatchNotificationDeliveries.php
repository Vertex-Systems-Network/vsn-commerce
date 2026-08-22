<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Mobile\Services\FcmPushService;
use App\Domain\Notifications\Services\TransactionalEmailProviderManager;
use App\Domain\Security\Services\SmsProviderManager;
use App\Models\NotificationDelivery;
use App\Models\NotificationDeliveryAttempt;

/** Defines the DispatchNotificationDeliveries class and its project responsibilities. */
final class DispatchNotificationDeliveries
{
    /** Initializes the DispatchNotificationDeliveries instance and its dependencies. */
    public function __construct(
        private readonly TransactionalEmailProviderManager $email,
        private readonly SmsProviderManager $sms,
        private readonly FcmPushService $push,
    ) {
    }

    /** Executes the dispatch notification deliveries operation. */
    public function execute(int $limit = 200): array
    {
        $sent = 0;
        $disabled = 0;
        $failed = 0;

        NotificationDelivery::query()
            ->where('status', 'pending')
            ->where(/** Inline callback for this operation. */ fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->with('notification.user.profile')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(/** Inline callback for this operation. */ function (NotificationDelivery $delivery) use (&$sent, &$disabled, &$failed): void {
                $claimed = NotificationDelivery::query()->whereKey($delivery->id)->where('status', 'pending')
                    ->update(['status' => 'processing', 'attempts' => $delivery->attempts + 1]);
                if (! $claimed) return;

                $delivery = $delivery->fresh('notification.user.profile');
                $attemptNo = (int) $delivery->attempts;
                $started = now();
                $providerName = null;

                try {
                    $notification = $delivery->notification;
                    $user = $notification?->user;
                    if (! $notification || ! $user) throw new \RuntimeException('Notification recipient is unavailable.');

                    if ($delivery->channel === 'email') {
                        $provider = $this->email->provider();
                        $providerName = $provider->name();
                        $action = $notification->action_url ? rtrim((string) config('vsn.frontend_url'), '/').$notification->action_url : null;
                        $text = $notification->body.($action ? "\n\nView details: {$action}" : '')."\n\nYou can change communication preferences in VSN Ecommerce settings.";
                        $html = '<p>'.e($notification->body).'</p>'.($action ? '<p><a href="'.e($action).'">View details</a></p>' : '').'<p><small>You can change communication preferences in VSN Ecommerce settings.</small></p>';
                        $provider->send($user->email, $notification->title, $text, $html, 'marketplace-notification-'.$delivery->id);
                        $this->markSent($delivery, $attemptNo, $providerName, $started);
                        $sent++;
                        return;
                    }

                    if ($delivery->channel === 'sms') {
                        $phone = $user->profile?->phone;
                        if (! $phone || ! $user->profile?->phone_verified_at) {
                            $this->markDisabled($delivery, $attemptNo, 'Verified phone number is unavailable.', $started);
                            $disabled++;
                            return;
                        }
                        $provider = $this->sms->provider();
                        $providerName = $provider->name();
                        $provider->send($phone, $notification->title.': '.$notification->body);
                        $this->markSent($delivery, $attemptNo, $providerName, $started);
                        $sent++;
                        return;
                    }

                    if ($delivery->channel === 'push') {
                        $providerName = 'fcm_http_v1';
                        if (! $this->push->configured()) {
                            $this->markDisabled($delivery, $attemptNo, 'FCM HTTP v1 provider is not configured.', $started, $providerName);
                            $disabled++;
                            return;
                        }
                        $result = $this->push->deliver($notification, $delivery);
                        if (($result['sent'] ?? 0) < 1) {
                            $reason = ($result['invalidated'] ?? 0) > 0
                                ? 'All registered Android push tokens were invalid and have been retired.'
                                : 'No active Android push registration is available.';
                            $this->markDisabled($delivery, $attemptNo, $reason, $started, $providerName);
                            $disabled++;
                            return;
                        }
                        $delivery->refresh();
                        $this->markSent($delivery, $attemptNo, $providerName, $started);
                        $sent++;
                        return;
                    }

                    $error = "{$delivery->channel} provider is not configured.";
                    $this->markDisabled($delivery, $attemptNo, $error, $started);
                    $disabled++;
                } catch (\Throwable $e) {
                    $attempts = (int) $delivery->fresh()->attempts;
                    $status = $attempts >= max(1, (int) config('vsn.notifications.max_attempts', 3)) ? 'failed' : 'pending';
                    $delivery->update([
                        'status' => $status,
                        'available_at' => now()->addMinutes(min(30, 2 ** max(1, $attempts))),
                        'last_error' => mb_substr($e->getMessage(), 0, 2000),
                    ]);
                    $this->attempt($delivery, $attemptNo, 'failed', $providerName, $e->getMessage(), $started);
                    $failed++;
                }
            });

        return compact('sent', 'disabled', 'failed');
    }

    /** Handles mark sent for the dispatch notification deliveries workflow. */
    private function markSent(NotificationDelivery $delivery, int $attemptNo, ?string $provider, $started): void
    {
        $delivery->update([
            'status' => 'sent',
            'sent_at' => now(),
            'last_error' => null,
            'metadata' => array_merge($delivery->metadata ?? [], ['provider' => $provider]),
        ]);
        $this->attempt($delivery, $attemptNo, 'sent', $provider, null, $started);
    }

    /** Handles mark disabled for the dispatch notification deliveries workflow. */
    private function markDisabled(NotificationDelivery $delivery, int $attemptNo, string $error, $started, ?string $provider = null): void
    {
        $delivery->update(['status' => 'disabled', 'last_error' => $error]);
        $this->attempt($delivery, $attemptNo, 'disabled', $provider, $error, $started);
    }

    /** Handles attempt for the dispatch notification deliveries workflow. */
    private function attempt(NotificationDelivery $delivery, int $number, string $status, ?string $provider, ?string $error, $started): void
    {
        NotificationDeliveryAttempt::create([
            'notification_delivery_id' => $delivery->id,
            'attempt_number' => $number,
            'status' => $status,
            'provider' => $provider,
            'error' => $error ? mb_substr($error, 0, 2000) : null,
            'started_at' => $started,
            'finished_at' => now(),
        ]);
    }
}
