<?php
namespace App\Enums;
/** Defines the ReturnRequestStatus enum and its project responsibilities. */
enum ReturnRequestStatus: string
{
    case Submitted='submitted'; case Reviewing='reviewing'; case Approved='approved'; case Rejected='rejected';
    case InTransit='in_transit'; case Received='received'; case Refunded='refunded'; case Replaced='replaced'; case Disputed='disputed'; case Cancelled='cancelled';
}
