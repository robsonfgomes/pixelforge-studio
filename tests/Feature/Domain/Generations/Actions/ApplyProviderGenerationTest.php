<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Generations\Actions;

use App\Domain\Generations\Actions\ApplyProviderGeneration;
use App\Domain\Generations\Data\ProviderGenerationData;
use App\Domain\Generations\Enums\GenerationStatus;
use App\Domain\Generations\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\FakePixelforgeApi;
use Tests\TestCase;

class ApplyProviderGenerationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function itMovesAQueuedGenerationToProcessing(): void
    {
        $generation = Generation::factory()->submitted()->create();

        $this->apply($generation, FakePixelforgeApi::providerGeneration($generation->id, GenerationStatus::Processing));

        $generation->refresh();
        $this->assertSame(GenerationStatus::Processing, $generation->status);
        $this->assertSame('2026-11-04T14:03:13+00:00', $generation->started_at?->toIso8601String());
        $this->assertNull($generation->charge);
    }

    #[Test]
    public function itRecordsTheUsageCostChargeAndImageOnSuccess(): void
    {
        $generation = Generation::factory()->processing()->create();

        $this->apply($generation, FakePixelforgeApi::providerGeneration($generation->id, GenerationStatus::Succeeded));

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame([12, 765, 6_450, 3_184], [
            $generation->input_text_tokens,
            $generation->input_image_tokens,
            $generation->output_tokens,
            $generation->reasoning_tokens,
        ]);
        $this->assertSame('0.305510000', $generation->provider_cost?->toDecimalString());
        $this->assertSame('0.381887500', $generation->charge?->toDecimalString());
        $this->assertStringStartsWith('https://pixelforge.promptedit.dev/images/', (string) $generation->image_url);
        $this->assertSame('2026-11-05T14:03:29+00:00', $generation->image_url_expires_at?->toIso8601String());
        $this->assertSame('2026-11-04T14:03:29+00:00', $generation->completed_at?->toIso8601String());
    }

    #[Test]
    public function itChargesWithTheRatesBilledOnTheGeneration(): void
    {
        $generation = Generation::factory()->processing()->create();
        $providerGeneration = FakePixelforgeApi::providerGeneration($generation->id, GenerationStatus::Succeeded, [
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '45.00',
                'reasoning_tokens' => '12.50',
            ],
        ]);

        $this->apply($generation, $providerGeneration);

        $generation->refresh();
        $this->assertSame('0.337760000', $generation->provider_cost?->toDecimalString());
        $this->assertSame('0.422200000', $generation->charge?->toDecimalString());
    }

    #[Test]
    public function itRecordsTheFailureAtNoCost(): void
    {
        $generation = Generation::factory()->processing()->create();

        $this->apply($generation, FakePixelforgeApi::providerGeneration($generation->id, GenerationStatus::Failed));

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('internal_error', $generation->error_code);
        $this->assertStringStartsWith('Something went wrong on our side', (string) $generation->error_message);
        $this->assertSame(0, $generation->charge?->nanodollars);
        $this->assertSame(0, $generation->provider_cost?->nanodollars);
        $this->assertSame('2026-11-04T14:03:29+00:00', $generation->completed_at?->toIso8601String());
    }

    #[Test]
    public function itGoesStraightToSucceededWhenTheProcessingEventIsMissing(): void
    {
        $generation = Generation::factory()->submitted()->create();

        $this->apply($generation, FakePixelforgeApi::providerGeneration($generation->id, GenerationStatus::Succeeded));

        $this->assertSame(GenerationStatus::Succeeded, $generation->refresh()->status);
    }

    #[Test]
    public function itIgnoresALateProcessingEventAfterSuccess(): void
    {
        $generation = Generation::factory()->processing()->create();
        $this->apply($generation, FakePixelforgeApi::providerGeneration($generation->id, GenerationStatus::Succeeded));

        $this->apply($generation, FakePixelforgeApi::providerGeneration($generation->id, GenerationStatus::Processing));

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame('0.381887500', $generation->charge?->toDecimalString());
    }

    #[Test]
    public function itChargesOnlyOnceWhenTheResultArrivesAgain(): void
    {
        $generation = Generation::factory()->processing()->create();
        $this->apply($generation, FakePixelforgeApi::providerGeneration($generation->id, GenerationStatus::Succeeded));
        $differentUsage = FakePixelforgeApi::providerGeneration($generation->id, GenerationStatus::Succeeded, [
            'usage' => [
                'input_text_tokens' => 99,
                'input_image_tokens' => 765,
                'output_tokens' => 6450,
                'reasoning_tokens' => 8000,
                'total_tokens' => 15314,
            ],
        ]);

        $this->apply($generation, $differentUsage);

        $generation->refresh();
        $this->assertSame('0.381887500', $generation->charge?->toDecimalString());
        $this->assertSame(3_184, $generation->reasoning_tokens);
    }

    #[Test]
    public function itNeverTurnsAFailedGenerationIntoASuccess(): void
    {
        $generation = Generation::factory()->failed('internal_error', 'Something went wrong.')->create();

        $this->apply($generation, FakePixelforgeApi::providerGeneration($generation->id, GenerationStatus::Succeeded));

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame(0, $generation->charge?->nanodollars);
    }

    #[Test]
    public function itFillsThePixelforgeIdAndRatesButNeverTheSubmissionTime(): void
    {
        $generation = Generation::factory()->create();

        $this->apply($generation, FakePixelforgeApi::providerGeneration($generation->id, GenerationStatus::Processing));

        $generation->refresh();
        $this->assertSame(FakePixelforgeApi::PIXELFORGE_ID, $generation->pixelforge_id);
        $this->assertEquals(FakePixelforgeApi::documentedPriceTable()->rates->toArray(), $generation->rates);
        $this->assertNull($generation->submitted_at);
    }

    private function apply(Generation $generation, ProviderGenerationData $providerGeneration): void
    {
        $this->app->make(ApplyProviderGeneration::class)->handle($generation, $providerGeneration);
    }
}
