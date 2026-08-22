<?php

namespace App\Domain\Payments\Exceptions;

use RuntimeException;

/** Defines the PaymentException class and its project responsibilities. */
class PaymentException extends RuntimeException
{
    /** Initializes the PaymentException instance and its dependencies. */
    public function __construct(string $message, public readonly string $field = 'payment')
    {
        parent::__construct($message);
    }
}
