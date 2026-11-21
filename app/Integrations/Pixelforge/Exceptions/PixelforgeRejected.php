<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge\Exceptions;

use RuntimeException;

/**
 * Pixelforge rejected the request for good (a 4xx other than a retryable 429). Nothing was created or billed.
 */
final class PixelforgeRejected extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
