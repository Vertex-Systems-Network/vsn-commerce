<?php

namespace App\Enums;

/** Defines the GiftStatus enum and its project responsibilities. */
enum GiftStatus: string
{
    case AwaitingPayment = 'awaiting_payment';
    case Scheduled = 'scheduled';
    case Processing = 'processing';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
}
