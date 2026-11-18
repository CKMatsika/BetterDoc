<?php

declare(strict_types=1);

namespace App\Enums;

enum ContractStatus: string
{
    case Active = 'active';
    case ExpiringSoon = 'expiring_soon';
    case Expired = 'expired';
    case Terminated = 'terminated';
}
