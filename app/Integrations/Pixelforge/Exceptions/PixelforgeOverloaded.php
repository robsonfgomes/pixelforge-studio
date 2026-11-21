<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge\Exceptions;

use RuntimeException;

/**
 * Pixelforge answered 503 `overloaded`. Nothing was created.
 */
final class PixelforgeOverloaded extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $retryAfterSeconds)
    {
        parent::__construct($message);
    }
}
