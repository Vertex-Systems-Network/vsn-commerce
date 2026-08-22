<?php
namespace App\Notifications;
use App\Models\MarketplaceNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
/** Defines the MarketplaceEmailNotification class and its project responsibilities. */
class MarketplaceEmailNotification extends Notification
{
    /** Initializes the MarketplaceEmailNotification instance and its dependencies. */
    public function __construct(private readonly MarketplaceNotification $event){}
    /** Handles via for the marketplace email notification workflow. */
    public function via(object $notifiable):array{return ['mail'];}
    /** Handles to mail for the marketplace email notification workflow. */
    public function toMail(object $notifiable):MailMessage
    {
        $mail=(new MailMessage)->subject($this->event->title)->line($this->event->body);
        if($this->event->action_url)$mail->action('View details',rtrim((string)config('vsn.frontend_url'),'/').$this->event->action_url);
        return $mail->line('You can change communication preferences in your VSN Ecommerce settings.');
    }
}
