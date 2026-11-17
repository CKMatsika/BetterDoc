<?php

declare(strict_types=1);

namespace App\Enums;

enum SignatureRequestStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Signed = 'signed';
    case Declined = 'declined';
    case Reassigned = 'reassigned';
}
