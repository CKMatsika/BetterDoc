<?php

declare(strict_types=1);

namespace App\Enums;

enum LocalAgentStatus: string
{
    case PendingPair = 'pending_pair';
    case Paired = 'paired';
    case Revoked = 'revoked';
}
