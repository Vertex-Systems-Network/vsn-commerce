<?php

namespace App\Domain\Cart\Exceptions;

use DomainException;

/** Defines the CartValidationException class and its project responsibilities. */
class CartValidationException extends DomainException
{
    /** Initializes the CartValidationException instance and its dependencies. */
    public function __construct(string $message, public readonly string $field = 'cart')
    {
        parent::__construct($message);
    }
}
