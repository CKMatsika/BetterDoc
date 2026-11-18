<?php

declare(strict_types=1);

namespace App\Enums;

enum BackupTier: string
{
    case Tier1Local = 'tier1_local';
    case Tier2OffsiteNas = 'tier2_offsite_nas';
    case Tier3ImmutableUsb = 'tier3_immutable_usb';
}
