<?php
namespace App\Domain\Affiliate\Exceptions;

use RuntimeException;

/** Defines the AffiliateException class and its project responsibilities. */
class AffiliateException extends RuntimeException
{
    /** Initializes the AffiliateException instance and its dependencies. */
    public function __construct(string $message, public readonly string $field = 'affiliate')
    {
        parent::__construct($message);
    }
}
