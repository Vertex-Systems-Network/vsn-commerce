<?php

namespace App\Enums;

/** Defines the ReviewStatus enum and its project responsibilities. */
enum ReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
