<?php

declare(strict_types=1);

namespace App\Enums;

enum AnomalyStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Dismissed = 'dismissed';
    case Escalated = 'escalated';
}
