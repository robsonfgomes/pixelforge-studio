<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Resources;

use App\Domain\Generations\Enums\ImageQuality;
use App\Domain\Generations\Enums\ImageSize;
use App\Domain\Generations\Models\Generation;
use App\Http\Resources\GenerationResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerationResourceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function itSendsASucceededGenerationWithExactAndDisplayAmounts(): void
    {
        $this->freezeSecond();
        $generation = Generation::factory()->succeeded()->create([
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => ImageSize::Landscape,
            'quality' => ImageQuality::High,
            'image_url' => 'https://pixelforge.promptedit.dev/images/lighthouse.png',
        ]);

        $data = (new GenerationResource($generation->refresh()))->resolve();

        $this->assertSame([
            'id' => $generation->id,
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'aspectRatio' => '3:2',
            'quality' => 'high',
            'status' => 'succeeded',
            'submissionState' => null,
            'estimatedCharge' => ['amount' => '0.070137500', 'display' => '$0.0701'],
            'estimatedMaxCharge' => ['amount' => '0.087500000', 'display' => '$0.0875'],
            'charge' => ['amount' => '0.381887500', 'display' => '$0.3819'],
            'imageUrl' => 'https://pixelforge.promptedit.dev/images/lighthouse.png',
            'imageUrlExpiresAt' => now()->addDay()->toIso8601String(),
            'failureMessage' => null,
            'createdAt' => now()->toIso8601String(),
            'completedAt' => now()->toIso8601String(),
        ], $data);
    }

    #[Test]
    public function itSendsTheFriendlyReasonAndAZeroChargeForAFailure(): void
    {
        $generation = Generation::factory()->failed('content_policy_violation', 'The prompt was rejected.')->create();

        $data = (new GenerationResource($generation->refresh()))->resolve();

        $this->assertSame('failed', $data['status']);
        $this->assertSame('Prompt rejected by the content policy', $data['failureMessage']);
        $this->assertSame(['amount' => '0.000000000', 'display' => '$0.0000'], $data['charge']);
    }

    #[Test]
    public function itSendsNoChargeBeforeTheGenerationFinishes(): void
    {
        $generation = Generation::factory()->processing()->create();

        $data = (new GenerationResource($generation->refresh()))->resolve();

        $this->assertNull($data['charge']);
        $this->assertNull($data['failureMessage']);
    }

    #[Test]
    public function itSaysAQueuedGenerationIsWaitingToBeSubmitted(): void
    {
        $generation = Generation::factory()->create();

        $this->assertSame('waiting', $this->submissionStateOf($generation));
    }

    #[Test]
    public function itSaysAQueuedGenerationIsRetryingAfterAnAnswerOtherThan202(): void
    {
        $generation = Generation::factory()->uncertainSince(now()->subMinutes(30))->create(['submit_attempts' => 3]);

        $this->assertSame('retrying', $this->submissionStateOf($generation));
    }

    #[Test]
    public function itSaysASubmissionIsTakingLongerOnceItHasBeenUncertainForTheWholeWindow(): void
    {
        $generation = Generation::factory()->uncertainSince(now()->subMinutes(61))->create(['submit_attempts' => 40]);

        $this->assertSame('taking_longer', $this->submissionStateOf($generation));
    }

    #[Test]
    public function itSaysAGenerationIsTakingLongerOncePastThePagePollingWindow(): void
    {
        $generation = Generation::factory()->processing()->create(['created_at' => now()->subMinutes(121)]);

        $this->assertSame('taking_longer', $this->submissionStateOf($generation));
    }

    #[Test]
    public function itHasNoSubmissionStateOnceSubmittedAndOnTime(): void
    {
        $submitted = Generation::factory()->submitted()->create();
        $processing = Generation::factory()->processing()->create();

        $this->assertNull($this->submissionStateOf($submitted));
        $this->assertNull($this->submissionStateOf($processing));
    }

    private function submissionStateOf(Generation $generation): ?string
    {
        return (new GenerationResource($generation->refresh()))->resolve()['submissionState'];
    }
}
