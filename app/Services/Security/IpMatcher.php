<?php

declare(strict_types=1);

namespace App\Services\Security;

final class IpMatcher
{
    /**
     * @param  list<string>  $cidrs
     */
    public static function matchesAny(string $ip, array $cidrs): bool
    {
        if ($cidrs === []) {
            return true;
        }

        foreach ($cidrs as $cidr) {
            if (self::matches($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    public static function matches(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $maskBitsStr] = explode('/', $cidr, 2);
        $maskBits = (int) $maskBitsStr;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;
        if ($maskBits < 0 || $maskBits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($maskBits, 8);
        $remainingBits = $maskBits % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    }
}
