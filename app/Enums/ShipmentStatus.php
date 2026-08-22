<?php
namespace App\Enums;
/** Defines the ShipmentStatus enum and its project responsibilities. */
enum ShipmentStatus: string
{
    case Pending='pending';
    case LabelCreated='label_created';
    case ReadyForPickup='ready_for_pickup';
    case PickedUp='picked_up';
    case InTransit='in_transit';
    case OutForDelivery='out_for_delivery';
    case Delivered='delivered';
    case DeliveryFailed='delivery_failed';
    case ReturnToOrigin='return_to_origin';
    case ReturnedToSender='returned_to_sender';
    case Cancelled='cancelled';

    /** Handles terminal for the shipment status workflow. */
    public function terminal(): bool
    {
        return in_array($this, [self::Delivered,self::ReturnedToSender,self::Cancelled], true);
    }
}
