<?php
namespace App\Enums;
/** Defines the ProductAlertStatus enum and its project responsibilities. */
enum ProductAlertStatus:string { case Active='active'; case Triggered='triggered'; case Disabled='disabled'; }
