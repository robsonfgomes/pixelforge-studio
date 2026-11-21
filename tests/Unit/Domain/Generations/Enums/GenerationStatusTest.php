<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Generations\Enums;

use App\Domain\Generations\Enums\GenerationStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class GenerationStatusTest extends TestCase
{
    #[Test]
    #[TestWith([GenerationStatus::Queued, false])]
    #[TestWith([GenerationStatus::Processing, false])]
    #[TestWith([GenerationStatus::Succeeded, true])]
    #[TestWith([GenerationStatus::Failed, true])]
    public function itTreatsOnlySucceededAndFailedAsFinal(GenerationStatus $status, bool $isFinal): void
    {
        $this->assertSame($isFinal, $status->isFinal());
    }

    #[Test]
    #[TestWith([GenerationStatus::Queued, GenerationStatus::Processing, true])]
    #[TestWith([GenerationStatus::Queued, GenerationStatus::Succeeded, true])]
    #[TestWith([GenerationStatus::Queued, GenerationStatus::Failed, true])]
    #[TestWith([GenerationStatus::Processing, GenerationStatus::Succeeded, true])]
    #[TestWith([GenerationStatus::Processing, GenerationStatus::Failed, true])]
    #[TestWith([GenerationStatus::Queued, GenerationStatus::Queued, false])]
    #[TestWith([GenerationStatus::Processing, GenerationStatus::Queued, false])]
    #[TestWith([GenerationStatus::Succeeded, GenerationStatus::Processing, false])]
    #[TestWith([GenerationStatus::Succeeded, GenerationStatus::Failed, false])]
    #[TestWith([GenerationStatus::Failed, GenerationStatus::Succeeded, false])]
    public function itOnlyMovesForward(GenerationStatus $from, GenerationStatus $to, bool $isAllowed): void
    {
        $this->assertSame($isAllowed, $from->canTransitionTo($to));
    }
}
