<?php

namespace App\Enums;

/** Defines the ProductStatus enum and its project responsibilities. */
enum ProductStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Published = 'published';
    case Suspended = 'suspended';
    case Archived = 'archived';
}
