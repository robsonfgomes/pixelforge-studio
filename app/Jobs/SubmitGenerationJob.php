<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Generations\Actions\FailGeneration;
use App\Domain\Generations\Actions\SubmitGeneration;
use App\Domain\Generations\Enums\GenerationFailureReason;
use App\Domain\Generations\Models\Generation;
use App\Integrations\Pixelforge\Exceptions\PixelforgeOverloaded;
use App\Integrations\Pixelforge\Exceptions\PixelforgeRateLimited;
use App\Integrations\Pixelforge\Exceptions\PixelforgeUnavailable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Submits a generation to Pixelforge, retrying while Pixelforge says nothing was created (up to the submit window)
 * or while the outcome is uncertain (up to the uncertain-outcome window).
 */
#[UniqueFor(70 * 60)]
class SubmitGenerationJob implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Queueable;

    private const int MAX_BACKOFF_SECONDS = 60;

    public function __construct(public readonly string $generationId) {}

    public function uniqueId(): string
    {
        return $this->generationId;
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes((int) config('generations.uncertain_outcome_retry_window_minutes'));
    }

    public function handle(SubmitGeneration $submitGeneration, FailGeneration $failGeneration): void
    {
        $generation = Generation::query()->find($this->generationId);

        if ($generation === null) {
            return;
        }

        try {
            $submitGeneration->handle($generation);
        } catch (PixelforgeRateLimited|PixelforgeOverloaded $exception) {
            $generation->refresh();

            if ($generation->submission_uncertain_at === null && $this->submitWindowHasPassed($generation)) {
                $failGeneration->handle(
                    $generation,
                    GenerationFailureReason::PixelforgeBusy->value,
                    'Pixelforge kept answering that nothing was created until the submit window ran out.',
                );

                return;
            }

            $this->release($exception->retryAfterSeconds ?? $this->backoffSeconds());
        } catch (PixelforgeUnavailable) {
            $this->release($this->backoffSeconds());
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Gave up submitting a generation; it stays queued because Pixelforge may have created it.', [
            'generation_id' => $this->generationId,
            'reason' => $exception?->getMessage(),
        ]);
    }

    private function submitWindowHasPassed(Generation $generation): bool
    {
        $submitWindowMinutes = (int) config('generations.submit_retry_window_minutes');

        return $generation->created_at?->addMinutes($submitWindowMinutes)->isPast() ?? true;
    }

    private function backoffSeconds(): int
    {
        return min(self::MAX_BACKOFF_SECONDS, 2 ** $this->attempts());
    }
}
