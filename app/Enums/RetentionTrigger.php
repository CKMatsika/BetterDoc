<?php

declare(strict_types=1);

namespace App\Enums;

enum RetentionTrigger: string
{
    case CreatedAt = 'created_at';
    case Superseded = 'superseded';
    case Closed = 'closed';
    case ContractEnd = 'contract_end';
}
