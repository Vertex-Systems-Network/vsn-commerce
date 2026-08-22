<?php
namespace App\Enums;
/** Defines the ReturnResolution enum and its project responsibilities. */
enum ReturnResolution: string
{
    case OriginalPayment='refund_original'; case Coins='coins'; case Replacement='replacement'; case Dispute='dispute';
}
