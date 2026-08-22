<?php
namespace App\Domain\Messaging\Exceptions;
/** Defines the MessagingException class and its project responsibilities. */
class MessagingException extends \RuntimeException{public function __construct(string $message,public readonly string $field='message'){parent::__construct($message);}}
