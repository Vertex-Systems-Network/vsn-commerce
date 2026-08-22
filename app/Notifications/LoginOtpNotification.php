<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Defines the LoginOtpNotification class and its project responsibilities. */
class LoginOtpNotification extends Notification
{
    /** Initializes the LoginOtpNotification instance and its dependencies. */
    public function __construct(private readonly string $code)
    {
    }

    /** Handles via for the login otp notification workflow. */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** Handles to mail for the login otp notification workflow. */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your VSN Ecommerce sign-in code')
            ->line('Use this one-time code to sign in:')
            ->line($this->code)
            ->line('The code expires in 10 minutes. If you did not request it, ignore this message.');
    }
}
