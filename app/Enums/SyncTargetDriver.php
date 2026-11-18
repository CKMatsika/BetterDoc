<?php

declare(strict_types=1);

namespace App\Enums;

enum SyncTargetDriver: string
{
    case LocalMirror = 'local_mirror';
    case S3Compatible = 's3_compatible';
    case LaravelCloud = 'laravel_cloud';
    case SecondarySite = 'secondary_site';
}
