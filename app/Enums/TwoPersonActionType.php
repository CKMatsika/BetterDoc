<?php

declare(strict_types=1);

namespace App\Enums;

enum TwoPersonActionType: string
{
    case Declassify = 'declassify';
    case DeleteSensitive = 'delete_sensitive';
    case WidenAcl = 'widen_acl';
    case ElevateClearance = 'elevate_clearance';
    case GrantCompartment = 'grant_compartment';
    case BreakGlass = 'break_glass';
    case DisposeRecord = 'dispose_record';
}
