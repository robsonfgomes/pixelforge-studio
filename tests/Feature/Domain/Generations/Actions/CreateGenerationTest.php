<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Generations\Actions;

use App\Domain\Generations\Actions\CreateGeneration;
use App\Domain\Generations\Data\GenerationRequestData;
use App\Domain\Generations\Enums\GenerationStatus;
use App\Domain\Generations\Enums\ImageQuality;
use App\Domain\Generations\Enums\ImageSize;
use App\Domain\Generations\Models\Generation;
use App\Domain\Pricing\Exceptions\PriceTableUnavailable;
use App\Integrations\Pixelforge\PixelforgeApi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\FakePixelforgeApi;
use Tests\TestCase;

class CreateGenerationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function itStoresAQueuedGenerationWithTheEstimateTheUserSaw(): void
    {
        $this->app->instance(PixelforgeApi::class, FakePixelforgeApi::withDocumentedPrices());
        $user = User::factory()->create();

        $generation = $this->app->make(CreateGeneration::class)->handle($user, $this->worstCaseRequest());

        $generation = Generation::query()->findOrFail($generation->id);
        $this->assertTrue($generation->user->is($user));
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertSame(str_repeat('a', 400), $generation->prompt);
        $this->assertSame(ImageSize::Landscape, $generation->size);
        $this->assertSame(ImageQuality::High, $generation->quality);
        $this->assertSame(['https://example.com/a.png', 'https://example.com/b.png'], $generation->reference_images);
        $this->assertSame(412_562_500, $generation->estimated_charge->nanodollars);
        $this->assertSame(467_343_750, $generation->estimated_max_charge->nanodollars);
        // jsonb doesn't keep key order, so compare the content only.
        $this->assertEquals(FakePixelforgeApi::documentedPriceTable()->toArray(), $generation->estimate_price_table);
        $this->assertSame(0, $generation->submit_attempts);
    }

    #[Test]
    public function itSubmitsTheGenerationOnlyAfterTheTransactionCommits(): void
    {
        $pixelforge = FakePixelforgeApi::withDocumentedPrices();
        $this->app->instance(PixelforgeApi::class, $pixelforge);
        $user = User::factory()->create();

        $generation = DB::transaction(function () use ($user, $pixelforge): Generation {
            $generation = $this->app->make(CreateGeneration::class)->handle($user, $this->worstCaseRequest());
            $this->assertSame([], $pixelforge->createRequests);

            return $generation;
        });

        $this->assertCount(1, $pixelforge->createRequests);
        $this->assertSame($generation->id, $pixelforge->createRequests[0]['generationId']);
    }

    #[Test]
    public function itCreatesNothingWhenNoPriceTableIsAvailable(): void
    {
        $pixelforge = FakePixelforgeApi::withDocumentedPrices();
        $pixelforge->makePricingUnavailable();
        $this->app->instance(PixelforgeApi::class, $pixelforge);
        $user = User::factory()->create();

        $this->assertThrows(
            fn () => $this->app->make(CreateGeneration::class)->handle($user, $this->worstCaseRequest()),
            PriceTableUnavailable::class,
        );

        $this->assertDatabaseCount('generations', 0);
        $this->assertSame([], $pixelforge->createRequests);
    }

    private function worstCaseRequest(): GenerationRequestData
    {
        return new GenerationRequestData(
            str_repeat('a', 400),
            ImageSize::Landscape,
            ImageQuality::High,
            ['https://example.com/a.png', 'https://example.com/b.png'],
        );
    }
}
