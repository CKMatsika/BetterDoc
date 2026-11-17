<?php

declare(strict_types=1);

namespace App\Enums;

enum CdrStatus: string
{
    case Pending = 'pending';
    case Sanitized = 'sanitized';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
