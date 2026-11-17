<?php

declare(strict_types=1);

namespace App\Enums;

enum EnvelopeStatus: string
{
    case Draft = 'draft';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
}
