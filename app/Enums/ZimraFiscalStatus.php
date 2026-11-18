<?php

declare(strict_types=1);

namespace App\Enums;

enum ZimraFiscalStatus: string
{
    case Pending = 'pending';
    case QueuedOffline = 'queued_offline';
    case Fiscalized = 'fiscalized';
    case Failed = 'failed';
}
