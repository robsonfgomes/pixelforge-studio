<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge\Exceptions;

use RuntimeException;

/**
 * Pixelforge refused the request because of the per-minute or concurrency limit. Nothing was created.
 */
final class PixelforgeRateLimited extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $retryAfterSeconds,
    ) {
        parent::__construct($message);
    }
}
