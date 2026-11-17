<?php

declare(strict_types=1);

namespace App\Enums;

enum SignatureVerificationStatus: string
{
    case Valid = 'valid';
    case Altered = 'altered';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Unsigned = 'unsigned';
}
