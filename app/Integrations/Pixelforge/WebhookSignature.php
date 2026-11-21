<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge;

use App\Integrations\Pixelforge\Exceptions\InvalidPixelforgeSignature;
use App\Integrations\Pixelforge\Exceptions\PixelforgeWebhookSecretMissing;

/**
 * Verifies `Pixelforge-Signature: t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">` with the key's secret.
 */
final readonly class WebhookSignature
{
    private const int TOLERANCE_SECONDS = 300;

    public function __construct(private ?string $secret) {}

    /**
     * @throws InvalidPixelforgeSignature
     * @throws PixelforgeWebhookSecretMissing
     */
    public function verify(string $rawBody, ?string $header): void
    {
        if ($this->secret === null || $this->secret === '') {
            throw PixelforgeWebhookSecretMissing::notConfigured();
        }

        $parts = $this->parse($header ?? '');
        $timestamp = $parts['t'] ?? null;
        $signature = $parts['v1'] ?? null;

        if ($timestamp === null || preg_match('/^\d+$/', $timestamp) !== 1 || $signature === null) {
            throw InvalidPixelforgeSignature::missingOrMalformed();
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $this->secret);

        if (! hash_equals($expected, $signature)) {
            throw InvalidPixelforgeSignature::mismatch();
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            throw InvalidPixelforgeSignature::expired();
        }
    }

    /**
     * @return array<string, string>
     */
    private function parse(string $header): array
    {
        $parts = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);

            if ($key !== '' && $value !== null) {
                $parts[$key] = $value;
            }
        }

        return $parts;
    }
}
