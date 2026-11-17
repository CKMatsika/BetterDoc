<?php

declare(strict_types=1);

namespace App\Enums;

enum WriteDownMode: string
{
    case Strict = 'strict';
    case Advisory = 'advisory';
}
