<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Domain\Generations\Enums\GenerationStatus;
use App\Domain\Generations\Models\Generation;
use App\Integrations\Pixelforge\Exceptions\PixelforgeOverloaded;
use App\Integrations\Pixelforge\Exceptions\PixelforgeRateLimited;
use App\Integrations\Pixelforge\Exceptions\PixelforgeUnavailable;
use App\Integrations\Pixelforge\PixelforgeApi;
use App\Jobs\PollGenerationJob;
use App\Jobs\SubmitGenerationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fakes\FakePixelforgeApi;
use Tests\TestCase;
use Throwable;

class SubmitGenerationJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function itFinishesWithoutReleasingOnceTheGenerationIsSubmitted(): void
    {
        $this->fakePixelforge();
        $generation = Generation::factory()->create();

        $job = $this->runJob($generation->id);

        $job->assertNotReleased();
        $this->assertNotNull($generation->refresh()->pixelforge_id);
    }

    #[Test]
    #[TestWith(['rate limited', 7])]
    #[TestWith(['overloaded', 2])]
    public function itReleasesWithTheRetryAfterHeaderWhenNothingWasCreated(string $answer, int $retryAfter): void
    {
        $generation = Generation::factory()->create();
        $this->fakePixelforge()->respondToCreateWith($this->nothingWasCreated($answer, $retryAfter));

        $job = $this->runJob($generation->id);

        $job->assertReleased(delay: $retryAfter);
    }

    #[Test]
    #[TestWith([1, 2])]
    #[TestWith([3, 8])]
    #[TestWith([6, 60])]
    #[TestWith([20, 60])]
    public function itBacksOffExponentiallyUpToAMinuteWithoutARetryAfterHeader(int $attempt, int $delay): void
    {
        $generation = Generation::factory()->create();
        $this->fakePixelforge()->respondToCreateWith($this->nothingWasCreated('rate limited', retryAfter: null));

        $job = $this->runJob($generation->id, attempt: $attempt);

        $job->assertReleased(delay: $delay);
    }

    #[Test]
    public function itBacksOffAfterAnUncertainOutcome(): void
    {
        $generation = Generation::factory()->create();
        $this->fakePixelforge()->respondToCreateWith(PixelforgeUnavailable::unexpectedStatus(500));

        $job = $this->runJob($generation->id, attempt: 2);

        $job->assertReleased(delay: 4);
        $this->assertSame(GenerationStatus::Queued, $generation->refresh()->status);
    }

    #[Test]
    public function itFailsTheGenerationAsBusyOnceTheSubmitWindowRunsOut(): void
    {
        $generation = Generation::factory()->create();
        $this->travel(601)->seconds();
        $this->fakePixelforge()->respondToCreateWith($this->nothingWasCreated('rate limited', retryAfter: 5));

        $job = $this->runJob($generation->id);

        $job->assertNotReleased();
        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('pixelforge_busy', $generation->error_code);
        $this->assertSame(0, $generation->charge?->nanodollars);
    }

    #[Test]
    public function itKeepsRetryingPastTheSubmitWindowAfterAnUncertainOutcome(): void
    {
        $generation = Generation::factory()->uncertainSince(now())->create(['submit_attempts' => 1]);
        $this->travel(11)->minutes();
        $this->fakePixelforge()->respondToCreateWith($this->nothingWasCreated('rate limited', retryAfter: 5));

        $job = $this->runJob($generation->id);

        $job->assertReleased(delay: 5);
        $this->assertSame(GenerationStatus::Queued, $generation->refresh()->status);
    }

    #[Test]
    public function itDoesNothingWhenTheGenerationNoLongerExists(): void
    {
        $pixelforge = $this->fakePixelforge();

        $job = $this->runJob((string) Str::uuid7());

        $job->assertNotReleased();
        $this->assertSame([], $pixelforge->createRequests);
    }

    #[Test]
    public function itStopsRetryingAnHourAfterItWasDispatched(): void
    {
        $this->freezeSecond();

        $job = new SubmitGenerationJob((string) Str::uuid7());

        $this->assertEquals(now()->addHour(), $job->retryUntil());
    }

    #[Test]
    public function itLeavesTheGenerationQueuedAndLogsAnErrorWhenItGivesUp(): void
    {
        Log::spy();
        $generation = Generation::factory()->uncertainSince(now()->subHour())->create(['submit_attempts' => 40]);

        (new SubmitGenerationJob($generation->id))->failed(new MaxAttemptsExceededException('Retry window passed.'));

        $this->assertSame(GenerationStatus::Queued, $generation->refresh()->status);
        Log::shouldHaveReceived('error')->once();
    }

    #[Test]
    public function itQueuesOneSubmissionPerGeneration(): void
    {
        Queue::fake([SubmitGenerationJob::class]);
        $generation = Generation::factory()->create();

        SubmitGenerationJob::dispatch($generation->id);
        SubmitGenerationJob::dispatch($generation->id);

        Queue::assertPushedTimes(SubmitGenerationJob::class, 1);
    }

    private function fakePixelforge(): FakePixelforgeApi
    {
        Queue::fake([PollGenerationJob::class]);
        $pixelforge = FakePixelforgeApi::withDocumentedPrices();
        $this->app->instance(PixelforgeApi::class, $pixelforge);

        return $pixelforge;
    }

    private function runJob(string $generationId, int $attempt = 1): SubmitGenerationJob
    {
        $job = (new SubmitGenerationJob($generationId))->withFakeQueueInteractions();
        $job->job->attempts = $attempt;

        $this->app->call([$job, 'handle']);

        return $job;
    }

    private function nothingWasCreated(string $answer, ?int $retryAfter): Throwable
    {
        return $answer === 'overloaded'
            ? new PixelforgeOverloaded('Pixelforge is temporarily overloaded.', $retryAfter)
            : new PixelforgeRateLimited('rate_limit_exceeded', 'Slow down.', $retryAfter);
    }
}
