<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Generations\Actions;

use App\Domain\Generations\Actions\RecordWebhookEvent;
use App\Domain\Generations\Data\WebhookEventData;
use App\Domain\Generations\Enums\GenerationStatus;
use App\Domain\Generations\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\FakePixelforgeApi;
use Tests\TestCase;

class RecordWebhookEventTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function itRecordsTheEventAndAppliesTheGeneration(): void
    {
        $generation = Generation::factory()->processing()->create();
        $event = $this->generationEvent('evt_1', $generation->id, GenerationStatus::Succeeded);

        $this->record($event, deliveryAttempt: 2);

        $this->assertDatabaseHas('webhook_events', [
            'event_id' => 'evt_1',
            'type' => 'generation.succeeded',
            'generation_id' => $generation->id,
            'delivery_attempt' => 2,
        ]);
        $this->assertSame(GenerationStatus::Succeeded, $generation->refresh()->status);
    }

    #[Test]
    public function itIgnoresAnEventItAlreadyRecorded(): void
    {
        $generation = Generation::factory()->processing()->create();
        $this->record($this->generationEvent('evt_1', $generation->id, GenerationStatus::Succeeded));
        $resentWithOtherUsage = $this->generationEvent('evt_1', $generation->id, GenerationStatus::Succeeded, [
            'usage' => [
                'input_text_tokens' => 99,
                'input_image_tokens' => 765,
                'output_tokens' => 6450,
                'reasoning_tokens' => 8000,
                'total_tokens' => 15314,
            ],
        ]);

        $this->record($resentWithOtherUsage, deliveryAttempt: 2);

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertSame('0.381887500', $generation->refresh()->charge?->toDecimalString());
    }

    #[Test]
    public function itMatchesTheGenerationByPixelforgeIdWhenTheMetadataIsMissing(): void
    {
        $generation = Generation::factory()->processing()->create([
            'pixelforge_id' => FakePixelforgeApi::PIXELFORGE_ID,
        ]);
        $event = $this->generationEvent('evt_1', $generation->id, GenerationStatus::Succeeded, ['metadata' => []]);

        $this->record($event);

        $this->assertSame(GenerationStatus::Succeeded, $generation->refresh()->status);
    }

    #[Test]
    public function itRecordsAnEventForAnUnknownGenerationAndLogsIt(): void
    {
        Log::spy();
        $unknownId = (string) Str::uuid7();

        $this->record($this->generationEvent('evt_1', $unknownId, GenerationStatus::Succeeded));

        $this->assertDatabaseHas('webhook_events', ['event_id' => 'evt_1', 'generation_id' => null]);
        Log::shouldHaveReceived('warning')->once();
    }

    #[Test]
    public function itRecordsAPingWithoutTouchingAnyGeneration(): void
    {
        $generation = Generation::factory()->processing()->create();

        $this->record(FakePixelforgeApi::webhookEvent('evt_ping', 'ping'));

        $this->assertDatabaseHas('webhook_events', ['event_id' => 'evt_ping', 'type' => 'ping']);
        $this->assertSame(GenerationStatus::Processing, $generation->refresh()->status);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function generationEvent(
        string $eventId,
        string $generationId,
        GenerationStatus $status,
        array $overrides = [],
    ): array {
        return FakePixelforgeApi::webhookEvent(
            $eventId,
            "generation.{$status->value}",
            FakePixelforgeApi::generationObject($generationId, $status, $overrides),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(array $payload, int $deliveryAttempt = 1): void
    {
        $this->app->make(RecordWebhookEvent::class)->handle(WebhookEventData::fromPayload($payload, $deliveryAttempt));
    }
}
