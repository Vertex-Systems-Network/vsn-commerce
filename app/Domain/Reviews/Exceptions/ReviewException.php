<?php

namespace App\Domain\Reviews\Exceptions;

use RuntimeException;

/** Defines the ReviewException class and its project responsibilities. */
class ReviewException extends RuntimeException
{
    /** Initializes the ReviewException instance and its dependencies. */
    public function __construct(string $message, public readonly string $field = 'review')
    {
        parent::__construct($message);
    }
}
