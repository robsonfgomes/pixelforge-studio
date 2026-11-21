<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Domain\Generations\Actions\RecordWebhookEvent;
use App\Domain\Generations\Data\ProviderGenerationData;
use App\Domain\Generations\Data\WebhookEventData;
use App\Domain\Generations\Enums\GenerationStatus;
use App\Domain\Generations\Models\Generation;
use App\Integrations\Pixelforge\Exceptions\PixelforgeRateLimited;
use App\Integrations\Pixelforge\Exceptions\PixelforgeRejected;
use App\Integrations\Pixelforge\Exceptions\PixelforgeUnavailable;
use App\Integrations\Pixelforge\PixelforgeApi;
use App\Jobs\PollGenerationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fakes\FakePixelforgeApi;
use Tests\TestCase;

class PollGenerationJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function itAppliesAFinalResultAndStopsPolling(): void
    {
        $pixelforge = $this->fakePixelforge();
        $generation = Generation::factory()->processing()->create();
        $pixelforge->respondToRetrieveWith(
            FakePixelforgeApi::providerGeneration($generation->id, GenerationStatus::Succeeded),
        );

        $this->runJob($generation->id, attempt: 1);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame('0.381887500', $generation->charge?->toDecimalString());
        $this->assertSame([$generation->pixelforge_id], $pixelforge->retrieveRequests);
        Queue::assertNotPushed(PollGenerationJob::class);
    }

    #[Test]
    #[TestWith([1, 60])]
    #[TestWith([2, 120])]
    #[TestWith([3, 300])]
    #[TestWith([4, 600])]
    #[TestWith([7, 600])]
    public function itPollsAgainWithTheNextDelayWhileTheGenerationIsNotFinal(int $attempt, int $nextDelay): void
    {
        $pixelforge = $this->fakePixelforge();
        $generation = Generation::factory()->submitted()->create();
        $pixelforge->respondToRetrieveWith(
            FakePixelforgeApi::providerGeneration($generation->id, GenerationStatus::Processing),
        );

        $this->runJob($generation->id, $attempt);

        $this->assertSame(GenerationStatus::Processing, $generation->refresh()->status);
        Queue::assertPushed(
            PollGenerationJob::class,
            fn (PollGenerationJob $job): bool => $job->generationId === $generation->id
                && $job->attempt === $attempt + 1
                && $job->delay === $nextDelay,
        );
    }

    #[Test]
    public function itGivesUpAfterTheLastAttemptWithoutFailingTheGeneration(): void
    {
        Log::spy();
        $pixelforge = $this->fakePixelforge();
        $generation = Generation::factory()->processing()->create();
        $pixelforge->respondToRetrieveWith(
            FakePixelforgeApi::providerGeneration($generation->id, GenerationStatus::Processing),
        );

        $this->runJob($generation->id, attempt: 8);

        $this->assertSame(GenerationStatus::Processing, $generation->refresh()->status);
        Queue::assertNotPushed(PollGenerationJob::class);
        Log::shouldHaveReceived('warning')->once();
    }

    #[Test]
    public function itDoesNotAskPixelforgeAboutAFinalGeneration(): void
    {
        $pixelforge = $this->fakePixelforge();
        $generation = Generation::factory()->succeeded()->create();

        $this->runJob($generation->id, attempt: 2);

        $this->assertSame([], $pixelforge->retrieveRequests);
        Queue::assertNotPushed(PollGenerationJob::class);
    }

    #[Test]
    public function itWaitsOutARateLimitWithoutSpendingAnAttempt(): void
    {
        $pixelforge = $this->fakePixelforge();
        $generation = Generation::factory()->processing()->create();
        $pixelforge->respondToRetrieveWith(new PixelforgeRateLimited('rate_limit_exceeded', 'Slow down.', 7));

        $job = $this->runJob($generation->id, attempt: 3);

        $job->assertReleased(delay: 7);
        Queue::assertNotPushed(PollGenerationJob::class);
    }

    #[Test]
    public function itSpendsAnAttemptOnAnUncertainAnswer(): void
    {
        $pixelforge = $this->fakePixelforge();
        $generation = Generation::factory()->processing()->create();
        $pixelforge->respondToRetrieveWith(PixelforgeUnavailable::unexpectedStatus(502));

        $this->runJob($generation->id, attempt: 2);

        $this->assertSame(GenerationStatus::Processing, $generation->refresh()->status);
        Queue::assertPushed(PollGenerationJob::class, fn (PollGenerationJob $job): bool => $job->attempt === 3);
    }

    #[Test]
    public function itStopsAndLogsWhenPixelforgeDoesNotKnowTheGeneration(): void
    {
        Log::spy();
        $pixelforge = $this->fakePixelforge();
        $generation = Generation::factory()->processing()->create();
        $pixelforge->respondToRetrieveWith(new PixelforgeRejected(404, 'not_found', 'No such generation.'));

        $this->runJob($generation->id, attempt: 2);

        $this->assertSame(GenerationStatus::Processing, $generation->refresh()->status);
        Queue::assertNotPushed(PollGenerationJob::class);
        Log::shouldHaveReceived('error')->once();
    }

    #[Test]
    public function itChargesOnceWhenTheWebhookFinishesTheGenerationDuringThePoll(): void
    {
        $pixelforge = $this->fakePixelforge();
        $generation = Generation::factory()->processing()->create();
        $succeeded = FakePixelforgeApi::generationObject($generation->id, GenerationStatus::Succeeded);
        $pixelforge->respondToRetrieveWith(function () use ($succeeded): ProviderGenerationData {
            $this->app->make(RecordWebhookEvent::class)->handle(WebhookEventData::fromPayload(
                FakePixelforgeApi::webhookEvent('evt_1', 'generation.succeeded', $succeeded),
                deliveryAttempt: 1,
            ));

            return ProviderGenerationData::fromArray($succeeded);
        });

        $this->runJob($generation->id, attempt: 1);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame('0.381887500', $generation->charge?->toDecimalString());
        Queue::assertNotPushed(PollGenerationJob::class);
    }

    #[Test]
    public function itKeepsOnePollingChainPerGeneration(): void
    {
        Queue::fake([PollGenerationJob::class]);
        $generation = Generation::factory()->processing()->create();

        PollGenerationJob::dispatch($generation->id, 1);
        PollGenerationJob::dispatch($generation->id, 1);

        Queue::assertPushedTimes(PollGenerationJob::class, 1);
    }

    private function fakePixelforge(): FakePixelforgeApi
    {
        Queue::fake([PollGenerationJob::class]);
        $pixelforge = FakePixelforgeApi::withDocumentedPrices();
        $this->app->instance(PixelforgeApi::class, $pixelforge);

        return $pixelforge;
    }

    private function runJob(string $generationId, int $attempt): PollGenerationJob
    {
        $job = (new PollGenerationJob($generationId, $attempt))->withFakeQueueInteractions();

        $this->app->call([$job, 'handle']);

        return $job;
    }
}
