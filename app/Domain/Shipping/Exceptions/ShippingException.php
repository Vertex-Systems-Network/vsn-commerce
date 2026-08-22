<?php
namespace App\Domain\Shipping\Exceptions;
/** Defines the ShippingException class and its project responsibilities. */
class ShippingException extends \RuntimeException
{
    /** Initializes the ShippingException instance and its dependencies. */
    public function __construct(string $message, public readonly ?string $field = null, int $code = 0, ?\Throwable $previous = null)
    { parent::__construct($message, $code, $previous); }
}
