<?php

declare(strict_types=1);

namespace App\Enums;

enum PrincipalType: string
{
    case User = 'user';
    case OrgUnit = 'org_unit';
    case Role = 'role';
}
