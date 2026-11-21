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

    public static function invalidBody(?Throwable $previous = null): self
    {
        return new self('Pixelforge answered with a body that is not a valid JSON object.', 0, $previous);
    }

    /**
     * After an uncertain outcome Pixelforge may already have created the generation, so a later rejection proves
     * nothing either.
     */
    public static function afterUncertainOutcome(PixelforgeRejected $rejection): self
    {
        return new self(
            "Pixelforge answered {$rejection->errorCode} after an earlier uncertain outcome.",
            0,
            $rejection,
        );
    }
}
