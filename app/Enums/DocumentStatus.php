<?php

declare(strict_types=1);

namespace App\Enums;

enum DocumentStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case CheckedOut = 'checked_out';
    case Archived = 'archived';
    case WormLocked = 'worm_locked';
    case Disposed = 'disposed';
}
