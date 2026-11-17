<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class AirGapViolationException extends RuntimeException
{
    public function __construct(string $host, string $url)
    {
        parent::__construct(sprintf(
            'Air-gapped mode blocked outbound HTTP request to unauthorized host [%s] (%s).',
            $host,
            $url
        ));
    }
}
