<?php

namespace App\Enums;

/** Defines the InventoryReservationStatus enum and its project responsibilities. */
enum InventoryReservationStatus: string
{
    case Active = 'active';
    case Released = 'released';
    case Converted = 'converted';
    case Expired = 'expired';
}
