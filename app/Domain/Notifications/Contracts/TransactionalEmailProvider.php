<?php
namespace App\Domain\Notifications\Contracts;
/** Defines the TransactionalEmailProvider interface and its project responsibilities. */
interface TransactionalEmailProvider
{
    /** Handles name for the transactional email provider workflow. */
    public function name():string;
    /** Handles send for the transactional email provider workflow. */
    public function send(string $to,string $subject,string $text,?string $html=null,?string $idempotencyKey=null):void;
}
