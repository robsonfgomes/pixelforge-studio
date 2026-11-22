<?php

declare(strict_types=1);

namespace App\Domain\Generations\Exceptions;

use UnexpectedValueException;

final class InvalidProviderGeneration extends UnexpectedValueException
{
    public static function succeededWithoutUsage(string $pixelforgeId): self
    {
        return new self("The succeeded generation [{$pixelforgeId}] has no usage, so its cost can't be computed.");
    }
}
