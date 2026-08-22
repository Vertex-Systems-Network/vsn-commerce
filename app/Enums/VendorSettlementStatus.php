<?php
namespace App\Enums;
/** Defines the VendorSettlementStatus enum and its project responsibilities. */
enum VendorSettlementStatus:string { case HoldPayment='hold_payment'; case HoldDelivery='hold_delivery'; case HoldReturnWindow='hold_return_window'; case Available='available'; case PayoutPending='payout_pending'; case PartiallyPaid='partially_paid'; case Paid='paid'; case Reversed='reversed'; }
