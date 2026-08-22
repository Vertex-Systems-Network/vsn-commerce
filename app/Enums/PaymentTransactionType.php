<?php

namespace App\Enums;

/** Defines the PaymentTransactionType enum and its project responsibilities. */
enum PaymentTransactionType: string
{
    case Authorization = 'authorization';
    case Capture = 'capture';
    case Failure = 'failure';
    case Void = 'void';
    case Refund = 'refund';
}
