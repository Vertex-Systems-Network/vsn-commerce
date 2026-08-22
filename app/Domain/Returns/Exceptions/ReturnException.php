<?php
namespace App\Domain\Returns\Exceptions;
/** Defines the ReturnException class and its project responsibilities. */
class ReturnException extends \RuntimeException
{
    /** Initializes the ReturnException instance and its dependencies. */
    public function __construct(string $message, public readonly string $field='return') { parent::__construct($message); }
}
