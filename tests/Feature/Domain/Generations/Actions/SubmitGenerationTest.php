<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Generations\Actions;

use App\Domain\Generations\Actions\SubmitGeneration;
use App\Domain\Generations\Enums\GenerationStatus;
use App\Domain\Generations\Models\Generation;
use App\Domain\Pricing\Actions\GetPriceTable;
use App\Domain\Pricing\Data\TokenRatesData;
use App\Integrations\Pixelforge\Exceptions\PixelforgeOverloaded;
use App\Integrations\Pixelforge\Exceptions\PixelforgeRateLimited;
use App\Integrations\Pixelforge\Exceptions\PixelforgeRejected;
use App\Integrations\Pixelforge\Exceptions\PixelforgeUnavailable;
use App\Integrations\Pixelforge\PixelforgeApi;
use App\Jobs\PollGenerationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fakes\FakePixelforgeApi;
use Tests\TestCase;

class SubmitGenerationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function itStoresThePixelforgeIdRatesAndSubmissionTime(): void
    {
        $this->freezeSecond();
        $pixelforge = $this->fakePixelforge();
        $generation = Generation::factory()->create();
        $pixelforge->respondToCreateWith(
            FakePixelforgeApi::acceptedGeneration($generation->id, pixelforgeId: 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K'),
        );

        $this->submit($generation);

        $generation->refresh();
        $this->assertSame('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', $generation->pixelforge_id);
        // jsonb doesn't keep key order, so compare the content only.
        $this->assertEquals(FakePixelforgeApi::documentedPriceTable()->rates->toArray(), $generation->rates);
        $this->assertEquals(now(), $generation->submitted_at);
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertSame(0, $generation->submit_attempts);
    }

    #[Test]
    public function itSendsTheStoredRequestWithOurIdAsTheIdempotencyKey(): void
    {
        $pixelforge = $this->fakePixelforge();
        $generation = Generation::factory()->create([
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'reference_images' => ['https://example.com/style.png'],
        ]);

        $this->submit($generation);

        $this->assertCount(1, $pixelforge->createRequests);
        $this->assertSame($generation->id, $pixelforge->createRequests[0]['generationId']);
        $request = $pixelforge->createRequests[0]['request'];
        $this->assertSame('A lighthouse on a cliff at dawn, oil painting', $request->prompt);
        $this->assertSame($generation->size, $request->size);
        $this->assertSame($generation->quality, $request->quality);
        $this->assertSame(['https://example.com/style.png'], $request->referenceImages);
    }

    #[Test]
    public function itRetriesWithTheSameIdempotencyKeyAfterAnUncertainOutcome(): void
    {
        $pixelforge = $this->fakePixelforge();
        $generation = Generation::factory()->create();
        $pixelforge->respondToCreateWith(PixelforgeUnavailable::unexpectedStatus(500));

        $this->assertThrows(fn () => $this->submit($generation), PixelforgeUnavailable::class);
        $this->submit($generation->refresh());

        $this->assertSame(
            [$generation->id, $generation->id],
            array_column($pixelforge->createRequests, 'generationId'),
        );
        $this->assertNotNull($generation->refresh()->pixelforge_id);
    }

    #[Test]
    public function itInvalidatesTheCachedPriceTableWhenPixelforgeBillsDifferentRates(): void
    {
        $pixelforge = $this->fakePixelforge();
        $generation = Generation::factory()->create();
        Cache::put(GetPriceTable::CURRENT_CACHE_KEY, $generation->estimate_price_table);
        $newRates = TokenRatesData::fromArray([
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '45.00',
            'reasoning_tokens' => '12.50',
        ]);
        $pixelforge->respondToCreateWith(FakePixelforgeApi::acceptedGeneration($generation->id, rates: $newRates));

        $this->submit($generation);

        $this->assertFalse(Cache::has(GetPriceTable::CURRENT_CACHE_KEY));
        $this->assertEquals($newRates->toArray(), $generation->refresh()->rates);
    }

    #[Test]
    public function itKeepsTheCachedPriceTableWhenTheRatesMatch(): void
    {
        $this->fakePixelforge();
        $generation = Generation::factory()->create();
        Cache::put(GetPriceTable::CURRENT_CACHE_KEY, $generation->estimate_price_table);

        $this->submit($generation);

        $this->assertTrue(Cache::has(GetPriceTable::CURRENT_CACHE_KEY));
    }

    #[Test]
    #[DataProvider('definitiveErrors')]
    public function itFailsTheGenerationAtNoCostOnADefinitiveError(int $status, string $code): void
    {
        $pixelforge = $this->fakePixelforge();
        $generation = Generation::factory()->create();
        $pixelforge->respondToCreateWith(new PixelforgeRejected($status, $code, 'Pixelforge said no.'));

        $this->submit($generation);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame($code, $generation->error_code);
        $this->assertSame('Pixelforge said no.', $generation->error_message);
        $this->assertSame(0, $generation->charge?->nanodollars);
        $this->assertSame(0, $generation->provider_cost?->nanodollars);
        $this->assertSame(1, $generation->submit_attempts);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function definitiveErrors(): array
    {
        return [
            'content policy' => [400, 'content_policy_violation'],
            'daily limit' => [429, 'daily_limit_exceeded'],
            'validation' => [422, 'validation_failed'],
            'idempotency key reused' => [409, 'idempotency_key_reused'],
            'invalid api key' => [401, 'invalid_api_key'],
            'unknown 4xx' => [418, 'a_code_pixelforge_adds_later'],
        ];
    }

    #[Test]
    #[TestWith(['invalid_api_key', true])]
    #[TestWith(['validation_failed', true])]
    #[TestWith(['a_code_pixelforge_adds_later', true])]
    #[TestWith(['content_policy_violation', false])]
    #[TestWith(['daily_limit_exceeded', false])]
    public function itLogsAnErrorOnlyForDefinitiveErrorsThatMeanABug(string $code, bool $isLogged): void
    {
        Log::spy();
        $pixelforge = $this->fakePixelforge();
        $generation = Generation::factory()->create();
        $pixelforge->respondToCreateWith(new PixelforgeRejected(400, $code, 'Pixelforge said no.'));

        $this->submit($generation);

        if ($isLogged) {
            Log::shouldHaveReceived('error')->once();
        } else {
            Log::shouldNotHaveReceived('error');
        }
    }

    #[Test]
    public function itRecordsTheFirstUncertainOutcomeAndPassesItOn(): void
    {
        $this->freezeSecond();
        $pixelforge = $this->fakePixelforge();
        $generation = Generation::factory()->create();
        $pixelforge->respondToCreateWith(PixelforgeUnavailable::unexpectedStatus(500));

        $this->assertThrows(fn () => $this->submit($generation), PixelforgeUnavailable::class);

        $generation->refresh();
        $this->assertEquals(now(), $generation->submission_uncertain_at);
        $this->assertSame(1, $generation->submit_attempts);
        $this->assertSame('uncertain', $generation->last_submit_error_code);
        $this->assertSame(GenerationStatus::Queued, $generation->status);
    }

    #[Test]
    public function itKeepsTheTimeOfTheFirstUncertainOutcome(): void
    {
        $this->freezeSecond();
        $pixelforge = $this->fakePixelforge();
        $firstUncertainAt = now()->subMinutes(5);
        $generation = Generation::factory()->uncertainSince($firstUncertainAt)->create(['submit_attempts' => 3]);
        $pixelforge->respondToCreateWith(PixelforgeUnavailable::unexpectedStatus(500));

        $this->assertThrows(fn () => $this->submit($generation), PixelforgeUnavailable::class);

        $generation->refresh();
        $this->assertEquals($firstUncertainAt, $generation->submission_uncertain_at);
        $this->assertSame(4, $generation->submit_attempts);
    }

    #[Test]
    #[TestWith(['rate_limit_exceeded'])]
    #[TestWith(['concurrency_limit_exceeded'])]
    #[TestWith(['overloaded'])]
    public function itCountsANothingWasCreatedAnswerAndPassesItOn(string $answer): void
    {
        $pixelforge = $this->fakePixelforge();
        $generation = Generation::factory()->create();
        $exception = $answer === 'overloaded'
            ? new PixelforgeOverloaded('Pixelforge is temporarily overloaded.', 2)
            : new PixelforgeRateLimited($answer, 'Slow down.', 7);
        $pixelforge->respondToCreateWith($exception);

        $this->assertThrows(fn () => $this->submit($generation), $exception::class);

        $generation->refresh();
        $this->assertSame(1, $generation->submit_attempts);
        $this->assertSame($answer, $generation->last_submit_error_code);
        $this->assertNull($generation->submission_uncertain_at);
        $this->assertSame(GenerationStatus::Queued, $generation->status);
    }

    #[Test]
    public function itTreatsADefinitiveErrorAsUncertainAfterAnUncertainOutcome(): void
    {
        $pixelforge = $this->fakePixelforge();
        $generation = Generation::factory()->uncertainSince(now()->subMinute())->create(['submit_attempts' => 1]);
        $pixelforge->respondToCreateWith(new PixelforgeRejected(400, 'content_policy_violation', 'Rejected.'));

        $this->assertThrows(fn () => $this->submit($generation), PixelforgeUnavailable::class);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertNull($generation->error_code);
        $this->assertSame(2, $generation->submit_attempts);
    }

    #[Test]
    public function itDoesNotSubmitAGenerationTwice(): void
    {
        $pixelforge = $this->fakePixelforge();
        $generation = Generation::factory()->submitted()->create();

        $this->submit($generation);

        $this->assertSame([], $pixelforge->createRequests);
    }

    #[Test]
    public function itMakesSurePollingRunsWhenARetryFindsTheGenerationAlreadySubmitted(): void
    {
        $this->fakePixelforge();
        $generation = Generation::factory()->processing()->create();

        $this->submit($generation);

        Queue::assertPushed(
            PollGenerationJob::class,
            fn (PollGenerationJob $job): bool => $job->generationId === $generation->id
                && $job->attempt === 1
                && $job->delay === 45,
        );
    }

    #[Test]
    public function itStartsPollingFortyFiveSecondsAfterTheSubmission(): void
    {
        $this->fakePixelforge();
        $generation = Generation::factory()->create();

        $this->submit($generation);

        Queue::assertPushed(
            PollGenerationJob::class,
            fn (PollGenerationJob $job): bool => $job->generationId === $generation->id
                && $job->attempt === 1
                && $job->delay === 45,
        );
    }

    #[Test]
    public function itAppliesAReplayThatIsAlreadyFinalAndDoesNotPoll(): void
    {
        $pixelforge = $this->fakePixelforge();
        $generation = Generation::factory()->create();
        $pixelforge->respondToCreateWith(
            FakePixelforgeApi::providerGeneration($generation->id, GenerationStatus::Succeeded),
        );

        $this->submit($generation);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(381_887_500, $generation->charge?->nanodollars);
        $this->assertNotNull($generation->submitted_at);
        Queue::assertNotPushed(PollGenerationJob::class);
    }

    #[Test]
    public function itStillStartsPollingWhenAWebhookArrivedBeforeTheCreateResponse(): void
    {
        $this->fakePixelforge();
        $generation = Generation::factory()->create([
            'status' => GenerationStatus::Processing,
            'pixelforge_id' => FakePixelforgeApi::PIXELFORGE_ID,
        ]);

        $this->submit($generation);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Processing, $generation->status);
        $this->assertNotNull($generation->submitted_at);
        Queue::assertPushed(PollGenerationJob::class, 1);
    }

    #[Test]
    public function itDoesNotSubmitAFinalGeneration(): void
    {
        $pixelforge = $this->fakePixelforge();
        $generation = Generation::factory()->failed('content_policy_violation', 'Rejected.')->create();

        $this->submit($generation);

        $this->assertSame([], $pixelforge->createRequests);
        Queue::assertNotPushed(PollGenerationJob::class);
    }

    private function fakePixelforge(): FakePixelforgeApi
    {
        Queue::fake([PollGenerationJob::class]);
        $pixelforge = FakePixelforgeApi::withDocumentedPrices();
        $this->app->instance(PixelforgeApi::class, $pixelforge);

        return $pixelforge;
    }

    private function submit(Generation $generation): void
    {
        $this->app->make(SubmitGeneration::class)->handle($generation);
    }
}
