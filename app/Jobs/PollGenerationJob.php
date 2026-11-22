<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Generations\Actions\ApplyProviderGeneration;
use App\Domain\Generations\Models\Generation;
use App\Integrations\Pixelforge\Exceptions\PixelforgeOverloaded;
use App\Integrations\Pixelforge\Exceptions\PixelforgeRateLimited;
use App\Integrations\Pixelforge\Exceptions\PixelforgeRejected;
use App\Integrations\Pixelforge\Exceptions\PixelforgeUnavailable;
use App\Integrations\Pixelforge\PixelforgeApi;
use App\Jobs\Concerns\BacksOffFromPixelforge;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The fallback for lost webhooks: asks Pixelforge for the generation and applies it, then polls again with the next
 * delay until the generation is final or the attempts run out. Unique until processing, so there is one chain per
 * generation and a running attempt can still dispatch the next one.
 */
class PollGenerationJob implements ShouldBeUniqueUntilProcessing, ShouldQueueAfterCommit
{
    use BacksOffFromPixelforge, Queueable;

    public function __construct(public readonly string $generationId, public readonly int $attempt) {}

    public function uniqueId(): string
    {
        return $this->generationId;
    }

    /**
     * Seconds between retries after an unexpected error; the planned polling delays don't depend on it.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * Bounds the releases on 429 and 503 `overloaded`, which don't spend a polling attempt.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    public function handle(PixelforgeApi $pixelforge, ApplyProviderGeneration $applyProviderGeneration): void
    {
        $generation = Generation::query()->find($this->generationId);

        if ($generation === null || $generation->status->isFinal() || $generation->pixelforge_id === null) {
            return;
        }

        try {
            $providerGeneration = $pixelforge->retrieveGeneration($generation->pixelforge_id);
        } catch (PixelforgeRateLimited|PixelforgeOverloaded $exception) {
            $this->release($this->retryDelaySeconds(
                $exception->retryAfterSeconds,
                $exception instanceof PixelforgeRateLimited ? $exception->errorCode : null,
            ));

            return;
        } catch (PixelforgeRejected $exception) {
            Log::error('Pixelforge refused to report a generation; polling stops and the status stays as it is.', [
                'generation_id' => $generation->id,
                'pixelforge_id' => $generation->pixelforge_id,
                'code' => $exception->errorCode,
            ]);

            return;
        } catch (PixelforgeUnavailable) {
            $this->pollAgain();

            return;
        }

        $applyProviderGeneration->handle($generation, $providerGeneration);

        if (! $generation->refresh()->status->isFinal()) {
            $this->pollAgain();
        }
    }

    private function pollAgain(): void
    {
        /** @var list<int> $delays */
        $delays = config('generations.polling_delays_seconds');

        if ($this->attempt >= count($delays)) {
            Log::warning('Stopped polling a generation that is still not final; it keeps its last status.', [
                'generation_id' => $this->generationId,
                'attempts' => $this->attempt,
            ]);

            return;
        }

        self::dispatch($this->generationId, $this->attempt + 1)->delay($delays[$this->attempt]);
    }
}
