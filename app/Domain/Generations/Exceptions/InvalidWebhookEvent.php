<?php

declare(strict_types=1);

namespace App\Domain\Generations\Exceptions;

use UnexpectedValueException;

final class InvalidWebhookEvent extends UnexpectedValueException
{
    public static function missingIdOrType(): self
    {
        return new self('The webhook body is not a Pixelforge event: it has no id or type.');
    }
}
