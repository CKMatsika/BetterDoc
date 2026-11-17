<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Thrown when MAC clearance, compartment, or HR subject shield denies access.
 * Always renders as HTTP 404 to prevent UUID existence oracles.
 */
class DocumentAccessDeniedException extends NotFoundHttpException
{
    public function __construct(string $message = 'Document not found.')
    {
        parent::__construct($message);
    }
}
