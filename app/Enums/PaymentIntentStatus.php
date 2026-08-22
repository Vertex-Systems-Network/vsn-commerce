<?php

namespace App\Enums;

/** Defines the PaymentIntentStatus enum and its project responsibilities. */
enum PaymentIntentStatus: string
{
    case Creating = 'creating';
    case RequiresAction = 'requires_action';
    case Authorized = 'authorized';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case NeedsReview = 'needs_review';
}
