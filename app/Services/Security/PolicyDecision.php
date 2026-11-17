<?php

declare(strict_types=1);

namespace App\Services\Security;

final readonly class PolicyDecision
{
    public function __construct(
        public bool $allowed,
        public string $reason,
        public bool $hideExistence = false,
        public bool $isBreakGlass = false,
        public bool $requiresTwoPerson = false,
    ) {}

    public static function allow(string $reason = 'allowed', bool $isBreakGlass = false): self
    {
        return new self(
            allowed: true,
            reason: $reason,
            hideExistence: false,
            isBreakGlass: $isBreakGlass,
            requiresTwoPerson: false,
        );
    }

    public static function denyHidden(string $reason): self
    {
        return new self(
            allowed: false,
            reason: $reason,
            hideExistence: true,
        );
    }

    public static function denyForbidden(string $reason, bool $requiresTwoPerson = false): self
    {
        return new self(
            allowed: false,
            reason: $reason,
            hideExistence: false,
            requiresTwoPerson: $requiresTwoPerson,
        );
    }
}
