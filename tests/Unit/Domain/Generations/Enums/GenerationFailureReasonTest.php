<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Generations\Enums;

use App\Domain\Generations\Enums\GenerationFailureReason;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class GenerationFailureReasonTest extends TestCase
{
    #[Test]
    #[TestWith(['content_policy_violation', 'Prompt rejected by the content policy'])]
    #[TestWith(['daily_limit_exceeded', 'Daily capacity reached, try again after 00:00 UTC'])]
    #[TestWith(['generation_failed', "We couldn't create an image for this prompt. Try rewording it."])]
    #[TestWith(['pixelforge_busy', 'The image service is busy right now. Please try again.'])]
    public function itGivesAFriendlyMessageForEachKnownCode(string $code, string $message): void
    {
        $this->assertSame($message, GenerationFailureReason::fromCode($code)->message());
    }

    #[Test]
    #[TestWith(['internal_error'])]
    #[TestWith(['validation_failed'])]
    #[TestWith(['idempotency_key_reused'])]
    #[TestWith(['invalid_api_key'])]
    #[TestWith(['a_code_pixelforge_adds_later'])]
    public function itFallsBackToAGenericMessageForAnyOtherCode(string $code): void
    {
        $this->assertSame(
            'Something went wrong while generating. Please try again.',
            GenerationFailureReason::fromCode($code)->message(),
        );
    }
}
