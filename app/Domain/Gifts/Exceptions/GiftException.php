<?php

namespace App\Domain\Gifts\Exceptions;

use DomainException;

/** Defines the GiftException class and its project responsibilities. */
class GiftException extends DomainException
{
    /** Initializes the GiftException instance and its dependencies. */
    public function __construct(string $message, public readonly string $field = 'gift')
    {
        parent::__construct($message);
    }
}
