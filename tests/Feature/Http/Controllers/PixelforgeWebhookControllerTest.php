<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use App\Domain\Generations\Enums\GenerationStatus;
use App\Domain\Generations\Models\Generation;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\FakePixelforgeApi;
use Tests\TestCase;

class PixelforgeWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'whsec_test';

    #[Test]
    public function itAppliesASignedSucceededEventAndAnswers204(): void
    {
        config(['services.pixelforge.webhook_secret' => self::SECRET]);
        $generation = Generation::factory()->processing()->create();

        $response = $this->deliver($this->succeededEvent('evt_1', $generation->id), deliveryAttempt: 3);

        $response->assertNoContent();
        $this->assertDatabaseHas('webhook_events', [
            'event_id' => 'evt_1',
            'generation_id' => $generation->id,
            'delivery_attempt' => 3,
        ]);
        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame('0.381887500', $generation->charge?->toDecimalString());
    }

    #[Test]
    public function itRejectsAnInvalidSignatureWith400(): void
    {
        config(['services.pixelforge.webhook_secret' => self::SECRET]);
        $generation = Generation::factory()->processing()->create();

        $response = $this->deliver($this->succeededEvent('evt_1', $generation->id), secret: 'whsec_forged');

        $response->assertBadRequest();
        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertSame(GenerationStatus::Processing, $generation->refresh()->status);
    }

    #[Test]
    public function itRejectsAnExpiredSignatureWith400(): void
    {
        config(['services.pixelforge.webhook_secret' => self::SECRET]);
        $generation = Generation::factory()->processing()->create();

        $response = $this->deliver($this->succeededEvent('evt_1', $generation->id), signedAt: now()->subSeconds(301));

        $response->assertBadRequest();
        $this->assertDatabaseCount('webhook_events', 0);
    }

    #[Test]
    public function itChargesOnceWhenTheSameEventIsDeliveredTwice(): void
    {
        config(['services.pixelforge.webhook_secret' => self::SECRET]);
        $generation = Generation::factory()->processing()->create();
        $event = $this->succeededEvent('evt_1', $generation->id);

        $first = $this->deliver($event);
        $second = $this->deliver($event, deliveryAttempt: 2);

        $first->assertNoContent();
        $second->assertNoContent();
        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertSame('0.381887500', $generation->refresh()->charge?->toDecimalString());
    }

    #[Test]
    public function itKeepsTheSuccessWhenAProcessingEventArrivesLate(): void
    {
        config(['services.pixelforge.webhook_secret' => self::SECRET]);
        $generation = Generation::factory()->processing()->create();
        $this->deliver($this->succeededEvent('evt_2', $generation->id));

        $response = $this->deliver(FakePixelforgeApi::webhookEvent(
            'evt_1',
            'generation.processing',
            FakePixelforgeApi::generationObject($generation->id, GenerationStatus::Processing),
        ));

        $response->assertNoContent();
        $this->assertSame(GenerationStatus::Succeeded, $generation->refresh()->status);
    }

    #[Test]
    public function itAnswersAPingWith204(): void
    {
        config(['services.pixelforge.webhook_secret' => self::SECRET]);

        $response = $this->deliver(FakePixelforgeApi::webhookEvent('evt_ping', 'ping'));

        $response->assertNoContent();
        $this->assertDatabaseHas('webhook_events', ['event_id' => 'evt_ping']);
    }

    #[Test]
    public function itRejectsASignedBodyThatIsNotAnEventWith400(): void
    {
        config(['services.pixelforge.webhook_secret' => self::SECRET]);
        Log::spy();

        $response = $this->deliverRaw('{"hello":"world"}');

        $response->assertBadRequest();
        $this->assertDatabaseCount('webhook_events', 0);
        Log::shouldHaveReceived('error')->once();
    }

    #[Test]
    public function itAnswers500AndLogsWhenNoWebhookSecretIsConfigured(): void
    {
        config(['services.pixelforge.webhook_secret' => null]);
        Log::spy();

        $response = $this->deliver(FakePixelforgeApi::webhookEvent('evt_ping', 'ping'));

        $response->assertInternalServerError();
        $this->assertDatabaseCount('webhook_events', 0);
        Log::shouldHaveReceived('error')->once();
    }

    #[Test]
    public function itRunsWithoutSessionCsrfOrAuthMiddleware(): void
    {
        // Tests skip CSRF verification, so no request can prove the route is outside the web group; the route's own
        // middleware list is the only place to check it.
        $route = Route::getRoutes()->getByName('webhooks.pixelforge');

        $this->assertNotNull($route);
        $this->assertSame([], $route->gatherMiddleware());
    }

    /**
     * @return array<string, mixed>
     */
    private function succeededEvent(string $eventId, string $generationId): array
    {
        return FakePixelforgeApi::webhookEvent(
            $eventId,
            'generation.succeeded',
            FakePixelforgeApi::generationObject($generationId, GenerationStatus::Succeeded),
        );
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function deliver(
        array $event,
        int $deliveryAttempt = 1,
        string $secret = self::SECRET,
        ?DateTimeInterface $signedAt = null,
    ): TestResponse {
        return $this->deliverRaw(json_encode($event, JSON_THROW_ON_ERROR), $deliveryAttempt, $secret, $signedAt);
    }

    private function deliverRaw(
        string $body,
        int $deliveryAttempt = 1,
        string $secret = self::SECRET,
        ?DateTimeInterface $signedAt = null,
    ): TestResponse {
        $timestamp = ($signedAt ?? now())->getTimestamp();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        return $this->call('POST', '/webhooks/pixelforge', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => (string) $deliveryAttempt,
        ], content: $body);
    }
}
