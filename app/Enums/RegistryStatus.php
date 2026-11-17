<?php

declare(strict_types=1);

namespace App\Enums;

enum RegistryStatus: string
{
    case Registered = 'registered';
    case Dispatched = 'dispatched';
    case Filed = 'filed';
    case Closed = 'closed';
}
