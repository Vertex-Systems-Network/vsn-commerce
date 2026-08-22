<?php

namespace App\Enums;

/** Defines the CheckoutStatus enum and its project responsibilities. */
enum CheckoutStatus: string
{
    case Reserved = 'reserved';
    case Converted = 'converted';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
