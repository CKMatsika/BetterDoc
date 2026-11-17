<?php

declare(strict_types=1);

namespace App\Enums;

enum CertificateCustodyMode: string
{
    case HsmManaged = 'hsm_managed';
    case SmartcardPkcs11 = 'smartcard_pkcs11';
    case WebauthnBound = 'webauthn_bound';
}
