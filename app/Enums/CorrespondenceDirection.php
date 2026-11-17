<?php

declare(strict_types=1);

namespace App\Enums;

enum CorrespondenceDirection: string
{
    case Incoming = 'incoming';
    case Outgoing = 'outgoing';
    case Internal = 'internal';
}
