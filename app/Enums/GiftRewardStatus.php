<?php

namespace App\Enums;

/** Defines the GiftRewardStatus enum and its project responsibilities. */
enum GiftRewardStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Consumed = 'consumed';
}
