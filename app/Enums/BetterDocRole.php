<?php

declare(strict_types=1);

namespace App\Enums;

enum BetterDocRole: string
{
    case SystemAdmin = 'SystemAdmin';
    case RecordsOfficer = 'RecordsOfficer';
    case Approver = 'Approver';
    case Author = 'Author';
    case Reader = 'Reader';
    case Auditor = 'Auditor';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}
