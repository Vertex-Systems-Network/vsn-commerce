<?php

namespace App\Domain\Checkout\Exceptions;

use RuntimeException;

/** Defines the CheckoutValidationException class and its project responsibilities. */
class CheckoutValidationException extends RuntimeException
{
    /** Initializes the CheckoutValidationException instance and its dependencies. */
    public function __construct(string $message, public readonly string $field = 'checkout')
    {
        parent::__construct($message);
    }
}
