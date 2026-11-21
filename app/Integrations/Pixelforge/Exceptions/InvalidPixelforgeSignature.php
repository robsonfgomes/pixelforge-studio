<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge\Exceptions;

use RuntimeException;

final class InvalidPixelforgeSignature extends RuntimeException
{
    public static function missingOrMalformed(): self
    {
        return new self('The Pixelforge-Signature header is missing or malformed.');
    }

    public static function mismatch(): self
    {
        return new self('The Pixelforge signature does not match the body.');
    }

    public static function expired(): self
    {
        return new self('The Pixelforge signature timestamp is more than 5 minutes away from now.');
    }
}
