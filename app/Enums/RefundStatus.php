<?php
namespace App\Enums;
/** Defines the RefundStatus enum and its project responsibilities. */
enum RefundStatus: string
{
    case Pending='pending'; case Processing='processing'; case Completed='completed'; case ManualPaymentRequired='manual_payment_required'; case NeedsReview='needs_review'; case Failed='failed';
}
