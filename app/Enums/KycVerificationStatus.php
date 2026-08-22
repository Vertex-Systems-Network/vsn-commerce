<?php
namespace App\Enums;
/** Defines the KycVerificationStatus enum and its project responsibilities. */
enum KycVerificationStatus:string { case Pending='pending'; case Approved='approved'; case Rejected='rejected'; case Expired='expired'; }
