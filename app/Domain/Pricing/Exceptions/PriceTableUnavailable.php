<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use RuntimeException;
use Throwable;

final class PriceTableUnavailable extends RuntimeException
{
    public static function neverFetched(Throwable $previous): self
    {
        return new self('No Pixelforge price table has been fetched yet and Pixelforge is unavailable.', 0, $previous);
    }
}
