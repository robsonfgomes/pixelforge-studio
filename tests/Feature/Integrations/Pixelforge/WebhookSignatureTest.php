<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Pixelforge;

use App\Integrations\Pixelforge\Exceptions\InvalidPixelforgeSignature;
use App\Integrations\Pixelforge\Exceptions\PixelforgeWebhookSecretMissing;
use App\Integrations\Pixelforge\WebhookSignature;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class WebhookSignatureTest extends TestCase
{
    private const string SECRET = 'whsec_test';

    private const string BODY = '{"id":"evt_01JC2Z9B7K2M4P6R8T0V3X5Z7A","type":"ping"}';

    #[Test]
    #[TestWith([0])]
    #[TestWith([-300])]
    #[TestWith([300])]
    public function itAcceptsAFreshSignatureOfTheRawBody(int $secondsFromNow): void
    {
        $this->freezeSecond();
        $header = $this->signatureHeader(self::BODY, now()->addSeconds($secondsFromNow)->getTimestamp());

        (new WebhookSignature(self::SECRET))->verify(self::BODY, $header);

        $this->addToAssertionCount(1);
    }

    #[Test]
    #[TestWith([-301])]
    #[TestWith([301])]
    public function itRejectsASignatureOutsideFiveMinutes(int $secondsFromNow): void
    {
        $this->freezeSecond();
        $header = $this->signatureHeader(self::BODY, now()->addSeconds($secondsFromNow)->getTimestamp());

        $this->expectException(InvalidPixelforgeSignature::class);

        (new WebhookSignature(self::SECRET))->verify(self::BODY, $header);
    }

    #[Test]
    public function itRejectsABodyThatChangedAfterSigning(): void
    {
        $header = $this->signatureHeader(self::BODY, now()->getTimestamp());

        $this->expectException(InvalidPixelforgeSignature::class);

        (new WebhookSignature(self::SECRET))->verify(str_replace('ping', 'generation.succeeded', self::BODY), $header);
    }

    #[Test]
    public function itRejectsASignatureMadeWithAnotherSecret(): void
    {
        $header = $this->signatureHeader(self::BODY, now()->getTimestamp(), secret: 'whsec_another_key');

        $this->expectException(InvalidPixelforgeSignature::class);

        (new WebhookSignature(self::SECRET))->verify(self::BODY, $header);
    }

    #[Test]
    #[TestWith([null])]
    #[TestWith([''])]
    #[TestWith(['v1=abc'])]
    #[TestWith(['t=1762265009'])]
    #[TestWith(['nonsense'])]
    public function itRejectsAMissingOrMalformedHeader(?string $header): void
    {
        $this->expectException(InvalidPixelforgeSignature::class);

        (new WebhookSignature(self::SECRET))->verify(self::BODY, $header);
    }

    #[Test]
    #[TestWith([null])]
    #[TestWith([''])]
    public function itRefusesToVerifyWithoutASecret(?string $secret): void
    {
        $header = $this->signatureHeader(self::BODY, now()->getTimestamp());

        $this->expectException(PixelforgeWebhookSecretMissing::class);

        (new WebhookSignature($secret))->verify(self::BODY, $header);
    }

    private function signatureHeader(string $body, int $timestamp, string $secret = self::SECRET): string
    {
        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }
}
