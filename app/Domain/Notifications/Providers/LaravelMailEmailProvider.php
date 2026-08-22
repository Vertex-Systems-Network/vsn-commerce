<?php
namespace App\Domain\Notifications\Providers;
use App\Domain\Notifications\Contracts\TransactionalEmailProvider;
use Illuminate\Support\Facades\Mail;
/** Defines the LaravelMailEmailProvider class and its project responsibilities. */
final class LaravelMailEmailProvider implements TransactionalEmailProvider
{
    /** Handles name for the laravel mail email provider workflow. */
    public function name():string{return 'laravel_mail';}
    /** Handles send for the laravel mail email provider workflow. */
    public function send(string $to,string $subject,string $text,?string $html=null,?string $idempotencyKey=null):void
    {
        Mail::raw($text,/** Inline callback for this operation. */ function($message)use($to,$subject):void{$message->to($to)->subject($subject);});
    }
}
