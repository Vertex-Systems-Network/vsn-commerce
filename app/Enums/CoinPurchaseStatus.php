<?php
namespace App\Enums;
/** Defines the CoinPurchaseStatus enum and its project responsibilities. */
enum CoinPurchaseStatus: string { case Pending='pending'; case RequiresAction='requires_action'; case Paid='paid'; case Failed='failed'; case NeedsReview='needs_review'; case Cancelled='cancelled'; }
