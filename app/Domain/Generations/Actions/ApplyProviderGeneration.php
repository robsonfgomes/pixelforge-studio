<?php

declare(strict_types=1);

namespace App\Domain\Generations\Actions;

use App\Domain\Generations\Data\ProviderGenerationData;
use App\Domain\Generations\Enums\GenerationStatus;
use App\Domain\Generations\Exceptions\InvalidProviderGeneration;
use App\Domain\Generations\Models\Generation;
use App\Domain\Pricing\PricingCalculator;
use App\Domain\Pricing\ValueObjects\Money;
use Illuminate\Support\Facades\DB;

/**
 * Applies what Pixelforge reports about a generation (from a webhook, a poll or a create response). Statuses only
 * move forward, so the cost and charge are recorded once, by whichever report reaches `succeeded` first.
 */
final readonly class ApplyProviderGeneration
{
    public function __construct(private PricingCalculator $calculator) {}

    public function handle(Generation $generation, ProviderGenerationData $providerGeneration): void
    {
        DB::transaction(function () use ($generation, $providerGeneration): void {
            $locked = Generation::query()->whereKey($generation->getKey())->lockForUpdate()->firstOrFail();
            $locked->pixelforge_id ??= $providerGeneration->id;
            $locked->rates ??= $providerGeneration->rates->toArray();

            if ($locked->status->canTransitionTo($providerGeneration->status)) {
                $locked->forceFill([
                    'status' => $providerGeneration->status,
                    'started_at' => $providerGeneration->startedAt ?? $locked->started_at,
                    ...$this->resultOf($providerGeneration),
                ]);
            }

            $locked->save();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function resultOf(ProviderGenerationData $providerGeneration): array
    {
        return match ($providerGeneration->status) {
            GenerationStatus::Succeeded => $this->successOf($providerGeneration),
            GenerationStatus::Failed => [
                'error_code' => $providerGeneration->errorCode,
                'error_message' => $providerGeneration->errorMessage,
                'provider_cost' => Money::zero(),
                'charge' => Money::zero(),
                'completed_at' => $providerGeneration->completedAt,
            ],
            GenerationStatus::Queued, GenerationStatus::Processing => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function successOf(ProviderGenerationData $providerGeneration): array
    {
        $usage = $providerGeneration->usage;

        if ($usage === null) {
            throw InvalidProviderGeneration::succeededWithoutUsage($providerGeneration->id);
        }

        $providerCost = $this->calculator->providerCost($usage, $providerGeneration->rates);

        return [
            'input_text_tokens' => $usage->inputText,
            'input_image_tokens' => $usage->inputImage,
            'output_tokens' => $usage->output,
            'reasoning_tokens' => $usage->reasoning,
            'provider_cost' => $providerCost,
            'charge' => $this->calculator->charge($providerCost),
            'image_url' => $providerGeneration->imageUrl,
            'image_url_expires_at' => $providerGeneration->imageUrlExpiresAt,
            'completed_at' => $providerGeneration->completedAt,
        ];
    }
}
