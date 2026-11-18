<?php

declare(strict_types=1);

namespace App\Enums;

enum PrintJobStatus: string
{
    case Held = 'held';
    case Releasing = 'releasing';
    case Printed = 'printed';
    case Cancelled = 'cancelled';
    case Locked = 'locked';
    case Failed = 'failed';
}
