<?php

namespace App\Enums;

/** Defines the PaymentTransactionStatus enum and its project responsibilities. */
enum PaymentTransactionStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Pending = 'pending';
}
