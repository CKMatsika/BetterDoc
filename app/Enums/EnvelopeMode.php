<?php

declare(strict_types=1);

namespace App\Enums;

enum EnvelopeMode: string
{
    case Ordered = 'ordered';
    case Parallel = 'parallel';
}
