<?php

declare(strict_types=1);

namespace App\Domain\Generations\Actions;

use App\Domain\Generations\Data\ProviderGenerationData;
use App\Domain\Generations\Data\WebhookEventData;
use App\Domain\Generations\Models\Generation;
use App\Domain\Generations\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Records a webhook event once (deduplicated by its event id) and applies its generation in the same transaction,
 * so an event is either recorded and applied, or neither.
 */
final readonly class RecordWebhookEvent
{
    public function __construct(private ApplyProviderGeneration $applyProviderGeneration) {}

    public function handle(WebhookEventData $event): void
    {
        DB::transaction(function () use ($event): void {
            $generation = $event->generation === null ? null : $this->findGeneration($event->generation);

            $recorded = WebhookEvent::query()->insertOrIgnore([
                'event_id' => $event->id,
                'type' => $event->type,
                'generation_id' => $generation?->id,
                'delivery_attempt' => $event->deliveryAttempt,
                'payload' => json_encode($event->payload, JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            if ($recorded === 0 || $event->generation === null) {
                return;
            }

            if ($generation === null) {
                Log::warning('Pixelforge sent an event for a generation we do not know.', [
                    'event_id' => $event->id,
                    'pixelforge_id' => $event->generation->id,
                    'generation_id' => $event->generation->generationId,
                ]);

                return;
            }

            $this->applyProviderGeneration->handle($generation, $event->generation);
        });
    }

    /**
     * Matches by our id in `metadata.generation_id` first, which works even before the create response arrives.
     */
    private function findGeneration(ProviderGenerationData $providerGeneration): ?Generation
    {
        $generationId = $providerGeneration->generationId;

        if ($generationId !== null && Str::isUuid($generationId)) {
            $generation = Generation::query()->find($generationId);

            if ($generation !== null) {
                return $generation;
            }
        }

        return Generation::query()->where('pixelforge_id', $providerGeneration->id)->first();
    }
}
