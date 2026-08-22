<?php

namespace App\Enums;

/** Defines the ReviewCouponStatus enum and its project responsibilities. */
enum ReviewCouponStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Redeemed = 'redeemed';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
