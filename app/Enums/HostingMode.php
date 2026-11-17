<?php

declare(strict_types=1);

namespace App\Enums;

enum HostingMode: string
{
    case AirGappedOnPrem = 'air_gapped_on_prem';
    case LanWithCloudBackup = 'lan_with_cloud_backup';
    case FullCloud = 'full_cloud';

    public function label(): string
    {
        return match ($this) {
            self::AirGappedOnPrem => 'Air-Gapped On-Prem',
            self::LanWithCloudBackup => 'LAN + Optional Cloud Backup',
            self::FullCloud => 'Full Cloud',
        };
    }

    public function allowsClassifiedStorage(): bool
    {
        return $this !== self::FullCloud;
    }
}
