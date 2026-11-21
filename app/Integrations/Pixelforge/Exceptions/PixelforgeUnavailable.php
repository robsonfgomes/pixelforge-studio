<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge\Exceptions;

use RuntimeException;
use Throwable;

final class PixelforgeUnavailable extends RuntimeException
{
    public static function connectionFailed(Throwable $previous): self
    {
        return new self('Could not connect to Pixelforge.', 0, $previous);
    }

    public static function unexpectedStatus(int $status): self
    {
        return new self("Pixelforge answered with HTTP {$status}.");
    }

    public static function invalidBody(): self
    {
        return new self('Pixelforge answered with a body that is not a JSON object.');
    }
}
