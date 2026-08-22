<?php
namespace App\Enums;
/** Defines the AffiliateCommissionStatus enum and its project responsibilities. */
enum AffiliateCommissionStatus: string
{
    case Pending = 'pending';
    case Available = 'available';
    case Credited = 'credited';
    case Reversed = 'reversed';
    case Cancelled = 'cancelled';
}
