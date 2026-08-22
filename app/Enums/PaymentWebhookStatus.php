<?php

namespace App\Enums;

/** Defines the PaymentWebhookStatus enum and its project responsibilities. */
enum PaymentWebhookStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Duplicate = 'duplicate';
    case NeedsReview = 'needs_review';
    case Failed = 'failed';
}
