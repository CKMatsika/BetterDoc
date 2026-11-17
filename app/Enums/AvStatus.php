<?php

declare(strict_types=1);

namespace App\Enums;

enum AvStatus: string
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Quarantined = 'quarantined';
}
