<?php
namespace App\Domain\Games\Exceptions;

use RuntimeException;

/** Defines the GameException class and its project responsibilities. */
class GameException extends RuntimeException
{
    /** Initializes the GameException instance and its dependencies. */
    public function __construct(string $message, public readonly string $field = 'game')
    {
        parent::__construct($message);
    }
}
