<?php

declare(strict_types=1);

namespace App\Enums;

enum DispositionAction: string
{
    case Archive = 'archive';
    case Destroy = 'destroy';
    case ReviewTransfer = 'review_transfer';
}
