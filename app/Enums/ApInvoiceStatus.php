<?php

declare(strict_types=1);

namespace App\Enums;

enum ApInvoiceStatus: string
{
    case PendingMatch = 'pending_match';
    case Matched = 'matched';
    case VarianceFlagged = 'variance_flagged';
    case DuplicateBlocked = 'duplicate_blocked';
    case Approved = 'approved';
    case Exported = 'exported';
}
