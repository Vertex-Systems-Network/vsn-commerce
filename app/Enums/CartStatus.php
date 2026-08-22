<?php

namespace App\Enums;

/** Defines the CartStatus enum and its project responsibilities. */
enum CartStatus: string
{
    case Active = 'active';
    case Gift = 'gift';
    case Converted = 'converted';
    case Abandoned = 'abandoned';
}
