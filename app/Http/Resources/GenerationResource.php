<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Generations\Enums\GenerationFailureReason;
use App\Domain\Generations\Enums\GenerationStatus;
use App\Domain\Generations\Models\Generation;
use App\Domain\Pricing\Enums\DisplayRounding;
use App\Http\Concerns\FormatsMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Generation $resource
 */
class GenerationResource extends JsonResource
{
    use FormatsMoney;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $generation = $this->resource;

        return [
            'id' => $generation->id,
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'aspectRatio' => $generation->size->aspectRatio(),
            'quality' => $generation->quality->value,
            'status' => $generation->status->value,
            'submissionState' => $this->submissionState($generation),
            'estimatedCharge' => $this->amount($generation->estimated_charge, DisplayRounding::HalfUp),
            'estimatedMaxCharge' => $this->amount($generation->estimated_max_charge, DisplayRounding::Up),
            'charge' => $generation->charge === null
                ? null
                : $this->amount($generation->charge, DisplayRounding::HalfUp),
            'imageUrl' => $generation->image_url,
            'imageUrlExpiresAt' => $generation->image_url_expires_at?->toIso8601String(),
            'failureMessage' => $generation->status === GenerationStatus::Failed
                ? GenerationFailureReason::fromCode((string) $generation->error_code)->message()
                : null,
            'createdAt' => $generation->created_at?->toIso8601String(),
            'completedAt' => $generation->completed_at?->toIso8601String(),
        ];
    }

    /**
     * `waiting` and `retrying` describe a generation we haven't handed to Pixelforge yet; `taking_longer` one our jobs
     * have stopped working on while it is still not final.
     */
    private function submissionState(Generation $generation): ?string
    {
        if ($generation->status->isFinal()) {
            return null;
        }

        if ($this->isTakingLonger($generation)) {
            return 'taking_longer';
        }

        if ($generation->status === GenerationStatus::Queued && $generation->submitted_at === null) {
            return $generation->submit_attempts === 0 ? 'waiting' : 'retrying';
        }

        return null;
    }

    private function isTakingLonger(Generation $generation): bool
    {
        $uncertainWindowMinutes = (int) config('generations.uncertain_outcome_retry_window_minutes');
        $pagePollingWindowMinutes = (int) config('generations.page_polling_window_minutes');
        $uncertainSince = $generation->submission_uncertain_at;

        return ($uncertainSince !== null && $uncertainSince->addMinutes($uncertainWindowMinutes)->isPast())
            || ($generation->created_at?->addMinutes($pagePollingWindowMinutes)->isPast() ?? false);
    }
}
