<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge\Exceptions;

use RuntimeException;

final class PixelforgeWebhookSecretMissing extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('PIXELFORGE_WEBHOOK_SECRET is not set, so no Pixelforge webhook can be verified.');
    }
}
