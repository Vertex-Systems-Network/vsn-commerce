<?php
namespace App\Domain\Wallet\Exceptions;
use RuntimeException;
/** Defines the WalletException class and its project responsibilities. */
class WalletException extends RuntimeException
{
    /** Initializes the WalletException instance and its dependencies. */
    public function __construct(string $message, public readonly string $field = 'wallet') { parent::__construct($message); }
}
