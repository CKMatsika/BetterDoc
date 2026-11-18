<?php

declare(strict_types=1);

namespace App\Enums;

enum ObligationStatus: string
{
    case Open = 'open';
    case Fulfilled = 'fulfilled';
    case Overdue = 'overdue';
}
