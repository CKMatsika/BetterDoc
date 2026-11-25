<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Exceptions\AirGapViolationException;
use Psr\Http\Message\RequestInterface;

class BetterDocHttpClientGuard
{
    public function assertOutboundAllowed(RequestInterface $request): RequestInterface
    {
        if (! (bool) config('betterdoc.air_gapped', true)) {
            return $request;
        }

        $uri = $request->getUri();
        $host = strtolower($uri->getHost());

        if ($host === '') {
            return $request;
        }

        $allowed = [];
        foreach (config()->array('betterdoc.internal_allowlist_hosts', []) as $item) {
            if (is_string($item) && $item !== '') {
                $allowed[] = strtolower($item);
            }
        }
        foreach (config()->array('betterdoc.external_allowlist_hosts', []) as $item) {
            if (is_string($item) && $item !== '') {
                $allowed[] = strtolower($item);
            }
        }
        if (in_array($host, $allowed, true)) {
            return $request;
        }

        throw new AirGapViolationException($host, (string) $uri);
    }
}
