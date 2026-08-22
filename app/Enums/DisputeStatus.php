<?php
namespace App\Enums;
/** Defines the DisputeStatus enum and its project responsibilities. */
enum DisputeStatus: string { case Open='open'; case Reviewing='reviewing'; case Resolved='resolved'; case Rejected='rejected'; }
