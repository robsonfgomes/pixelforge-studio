<?php

declare(strict_types=1);

namespace App\Domain\Generations\Actions;

use App\Domain\Generations\Data\GenerationRequestData;
use App\Domain\Generations\Data\ProviderGenerationData;
use App\Domain\Generations\Enums\GenerationFailureReason;
use App\Domain\Generations\Models\Generation;
use App\Domain\Pricing\Actions\InvalidatePriceTable;
use App\Domain\Pricing\Data\PriceTableData;
use App\Integrations\Pixelforge\Exceptions\PixelforgeOverloaded;
use App\Integrations\Pixelforge\Exceptions\PixelforgeRateLimited;
use App\Integrations\Pixelforge\Exceptions\PixelforgeRejected;
use App\Integrations\Pixelforge\Exceptions\PixelforgeUnavailable;
use App\Integrations\Pixelforge\PixelforgeApi;
use App\Jobs\PollGenerationJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final readonly class SubmitGeneration
{
    /**
     * Rejections the user caused or Pixelforge's capacity explains; any other one means a bug or a misconfiguration.
     */
    /**
     * `last_submit_error_code` values of our own, next to Pixelforge's codes.
     */
    private const string OVERLOADED = 'overloaded';

    private const string UNCERTAIN = 'uncertain';

    private const array EXPECTED_REJECTIONS = [
        GenerationFailureReason::ContentPolicyViolation,
        GenerationFailureReason::DailyLimitExceeded,
    ];

    public function __construct(
        private PixelforgeApi $pixelforge,
        private FailGeneration $failGeneration,
        private ApplyProviderGeneration $applyProviderGeneration,
        private InvalidatePriceTable $invalidatePriceTable,
        private int $firstPollDelaySeconds,
    ) {}

    /**
     * Makes one attempt to create the generation at Pixelforge. The retry policy belongs to the caller.
     *
     * @throws PixelforgeRateLimited nothing was created, retry later
     * @throws PixelforgeOverloaded nothing was created, retry later
     * @throws PixelforgeUnavailable the outcome is uncertain, retry with the same key
     */
    public function handle(Generation $generation): void
    {
        if ($generation->status->isFinal()) {
            return;
        }

        if ($generation->submitted_at !== null) {
            // A retry after the create response was recorded: the run that recorded it may have died before it
            // started polling, the fallback for lost webhooks. The poll job is unique, so this never starts a second
            // chain.
            $this->startPolling($generation);

            return;
        }

        try {
            $providerGeneration = $this->pixelforge->createGeneration($this->requestFor($generation), $generation->id);
        } catch (PixelforgeRateLimited $exception) {
            $this->recordAttempt($generation, $exception->errorCode, isUncertain: false);

            throw $exception;
        } catch (PixelforgeOverloaded $exception) {
            $this->recordAttempt($generation, self::OVERLOADED, isUncertain: false);

            throw $exception;
        } catch (PixelforgeUnavailable $exception) {
            $this->recordAttempt($generation, self::UNCERTAIN, isUncertain: true);

            throw $exception;
        } catch (PixelforgeRejected $rejection) {
            $this->handleRejection($generation, $rejection);

            return;
        }

        $this->recordSubmission($generation, $providerGeneration);
    }

    private function startPolling(Generation $generation): void
    {
        PollGenerationJob::dispatch($generation->id, attempt: 1)->delay($this->firstPollDelaySeconds);
    }

    private function requestFor(Generation $generation): GenerationRequestData
    {
        return new GenerationRequestData(
            $generation->prompt,
            $generation->size,
            $generation->quality,
            $generation->reference_images,
        );
    }

    private function handleRejection(Generation $generation, PixelforgeRejected $rejection): void
    {
        $wasUncertain = $generation->submission_uncertain_at !== null;
        $this->recordAttempt(
            $generation,
            $wasUncertain ? self::UNCERTAIN : $rejection->errorCode,
            isUncertain: $wasUncertain,
        );

        if ($wasUncertain) {
            throw PixelforgeUnavailable::afterUncertainOutcome($rejection);
        }

        if (! in_array(GenerationFailureReason::tryFrom($rejection->errorCode), self::EXPECTED_REJECTIONS, true)) {
            Log::error('Pixelforge rejected a generation for a reason that points to a bug or a misconfiguration.', [
                'generation_id' => $generation->id,
                'status' => $rejection->status,
                'code' => $rejection->errorCode,
                'message' => $rejection->getMessage(),
            ]);
        }

        $this->failGeneration->handle($generation, $rejection->errorCode, $rejection->getMessage());
    }

    private function recordAttempt(Generation $generation, string $errorCode, bool $isUncertain): void
    {
        DB::transaction(function () use ($generation, $errorCode, $isUncertain): void {
            $locked = Generation::query()->whereKey($generation->getKey())->lockForUpdate()->firstOrFail();
            $locked->submit_attempts++;
            $locked->last_submit_error_code = $errorCode;

            if ($isUncertain && $locked->submission_uncertain_at === null) {
                $locked->submission_uncertain_at = now();
            }

            $locked->save();
        });
    }

    private function recordSubmission(Generation $generation, ProviderGenerationData $providerGeneration): void
    {
        $setSubmittedAt = DB::transaction(function () use ($generation, $providerGeneration): bool {
            $locked = Generation::query()->whereKey($generation->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->submitted_at !== null) {
                return false;
            }

            $locked->pixelforge_id ??= $providerGeneration->id;
            $locked->rates ??= $providerGeneration->rates->toArray();
            $locked->submitted_at = now();
            $locked->save();

            return true;
        });

        $this->applyProviderGeneration->handle($generation, $providerGeneration);

        if ($setSubmittedAt && ! $generation->refresh()->status->isFinal()) {
            $this->startPolling($generation);
        }

        $estimatedRates = PriceTableData::fromArray($generation->estimate_price_table)->rates;

        if ($providerGeneration->rates != $estimatedRates) {
            $this->invalidatePriceTable->handle();
        }
    }
}
