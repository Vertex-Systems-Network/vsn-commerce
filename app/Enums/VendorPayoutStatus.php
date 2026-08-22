<?php
namespace App\Enums;
/** Defines the VendorPayoutStatus enum and its project responsibilities. */
enum VendorPayoutStatus:string { case Requested='requested'; case Approved='approved'; case Processing='processing'; case Paid='paid'; case Failed='failed'; case Cancelled='cancelled'; }
