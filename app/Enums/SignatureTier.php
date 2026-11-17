<?php

declare(strict_types=1);

namespace App\Enums;

enum SignatureTier: string
{
    case Tier1Stamp = 'tier1_stamp';
    case Tier2Pades = 'tier2_pades';
    case Tier2Smartcard = 'tier2_smartcard';
    case Tier3Workflow = 'tier3_workflow';
}
