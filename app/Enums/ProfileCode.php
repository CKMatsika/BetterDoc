<?php

declare(strict_types=1);

namespace App\Enums;

enum ProfileCode: string
{
    case Defence = 'defence';
    case Financial = 'financial';
    case Government = 'government';
    case Corporate = 'corporate';

    public function label(): string
    {
        return match ($this) {
            self::Defence => 'Defence & National Security',
            self::Financial => 'Financial Services & Microfinance',
            self::Government => 'Government & Public Sector',
            self::Corporate => 'Corporate & SME',
        };
    }
}
