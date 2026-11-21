<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use UnexpectedValueException;

final class UnsupportedRatePrecision extends UnexpectedValueException
{
    public static function forRate(string $ratePerMillionTokens): self
    {
        return new self(
            "The rate [{$ratePerMillionTokens}] per 1M tokens has more than 3 decimals, "
            .'so it cannot be charged exactly in nanodollars per token.',
        );
    }
}
