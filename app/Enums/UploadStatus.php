<?php

declare(strict_types=1);

namespace App\Enums;

enum UploadStatus: string
{
    case Initiated = 'initiated';
    case Uploading = 'uploading';
    case Assembling = 'assembling';
    case Completed = 'completed';
    case Aborted = 'aborted';
    case Expired = 'expired';
}
