<?php

declare(strict_types=1);

namespace App\Enums;

enum ScanSourceType: string
{
    case EsclAirscan = 'escl_airscan';
    case Sane = 'sane';
    case HotFolder = 'hot_folder';
    case MailIn = 'mail_in';
    case TwainWia = 'twain_wia';
    case PwaCamera = 'pwa_camera';
}
