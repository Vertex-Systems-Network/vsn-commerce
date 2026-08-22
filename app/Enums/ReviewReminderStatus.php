<?php

namespace App\Enums;

/** Defines the ReviewReminderStatus enum and its project responsibilities. */
enum ReviewReminderStatus: string
{
    case Scheduled = 'scheduled';
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
}
