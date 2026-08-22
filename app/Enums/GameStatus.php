<?php
namespace App\Enums;

/** Defines the GameStatus enum and its project responsibilities. */
enum GameStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Open = 'open';
    case Closed = 'closed';
    case WinnerSelected = 'winner_selected';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';
}
