<?php
namespace App\Enums;
/** Defines the AffiliateAccountStatus enum and its project responsibilities. */
enum AffiliateAccountStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';
}
