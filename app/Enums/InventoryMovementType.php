<?php

namespace App\Enums;

/** Defines the InventoryMovementType enum and its project responsibilities. */
enum InventoryMovementType: string
{
    case StockIn = 'stock_in';
    case StockOut = 'stock_out';
    case Reservation = 'reservation';
    case ReservationRelease = 'reservation_release';
    case Sale = 'sale';
    case Return = 'return';
    case Adjustment = 'adjustment';
}
