<?php
namespace App\Notifications;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
/** Defines the EmailVerificationCodeNotification class and its project responsibilities. */
class EmailVerificationCodeNotification extends Notification
{
    use Queueable;
    /** Initializes the EmailVerificationCodeNotification instance and its dependencies. */
    public function __construct(private readonly string $code){}
    /** Handles via for the email verification code notification workflow. */
    public function via(object $notifiable):array{return ['mail'];}
    /** Handles to mail for the email verification code notification workflow. */
    public function toMail(object $notifiable):MailMessage{return (new MailMessage)->subject('Verify your VSN Ecommerce email')->line('Your email verification code is: '.$this->code)->line('This code expires in 10 minutes.')->line('If you did not request this code, you can ignore this message.');}
}
