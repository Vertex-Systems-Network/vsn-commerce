<?php
namespace App\Enums;
/** Defines the WalletHoldStatus enum and its project responsibilities. */
enum WalletHoldStatus: string { case Active = 'active'; case Captured = 'captured'; case Released = 'released'; case Expired = 'expired'; }
