<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Pixelforge;

use App\Domain\Generations\Data\GenerationRequestData;
use App\Domain\Generations\Enums\GenerationStatus;
use App\Domain\Generations\Enums\ImageQuality;
use App\Domain\Generations\Enums\ImageSize;
use App\Domain\Pricing\Data\TokenCountsData;
use App\Integrations\Pixelforge\Exceptions\PixelforgeOverloaded;
use App\Integrations\Pixelforge\Exceptions\PixelforgeRateLimited;
use App\Integrations\Pixelforge\Exceptions\PixelforgeRejected;
use App\Integrations\Pixelforge\Exceptions\PixelforgeUnavailable;
use App\Integrations\Pixelforge\HttpPixelforgeClient;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fakes\FakePixelforgeApi;
use Tests\TestCase;
use Throwable;

class HttpPixelforgeClientTest extends TestCase
{
    private const string GENERATION_ID = '019a4f2e-7c1d-7b3a-9e55-2d8f1b4e7a10';

    #[Test]
    public function itReturnsThePriceTableFromThePricingEndpoint(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'pixelforge.test/v1/pricing' => Http::response(FakePixelforgeApi::documentedPricingPayload()),
        ]);

        $table = $this->client()->pricing();

        $this->assertEquals(FakePixelforgeApi::documentedPriceTable(), $table);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://pixelforge.test/v1/pricing');
    }

    #[Test]
    public function itReportsPixelforgeAsUnavailableOnAServerError(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'pixelforge.test/v1/pricing' => Http::response(['error' => ['code' => 'overloaded']], 503),
        ]);

        $this->expectException(PixelforgeUnavailable::class);

        $this->client()->pricing();
    }

    #[Test]
    public function itReportsPixelforgeAsUnavailableWhenTheConnectionFails(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'pixelforge.test/v1/pricing' => Http::failedConnection(),
        ]);

        $this->expectException(PixelforgeUnavailable::class);

        $this->client()->pricing();
    }

    #[Test]
    public function itReportsPixelforgeAsUnavailableWhenTheBodyIsNotJson(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'pixelforge.test/v1/pricing' => Http::response('<html>Bad gateway</html>'),
        ]);

        $this->expectException(PixelforgeUnavailable::class);

        $this->client()->pricing();
    }

    #[Test]
    public function itCreatesAGenerationWithOurIdAsIdempotencyKeyAndMetadata(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'pixelforge.test/v1/generations' => Http::response($this->queuedGenerationBody(), 202),
        ]);

        $generation = $this->client()->createGeneration($this->lighthouseRequest(), self::GENERATION_ID);

        $this->assertSame('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', $generation->id);
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertSame(self::GENERATION_ID, $generation->generationId);
        $this->assertEquals(FakePixelforgeApi::documentedPriceTable()->rates, $generation->rates);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://pixelforge.test/v1/generations'
            && $request->header('Idempotency-Key') === [self::GENERATION_ID]
            && $request->header('Authorization') === ['Bearer test-api-key']
            && $request->data() === [
                'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
                'size' => '1536x1024',
                'quality' => 'high',
                'reference_images' => ['https://example.com/style.png'],
                'metadata' => ['generation_id' => self::GENERATION_ID],
            ]);
    }

    #[Test]
    #[TestWith(['rate_limit_exceeded', '7', 7])]
    #[TestWith(['concurrency_limit_exceeded', '5', 5])]
    #[TestWith(['rate_limit_exceeded', null, null])]
    public function itReportsARateLimitWithItsRetryAfter(
        string $code,
        ?string $retryAfter,
        ?int $retryAfterSeconds,
    ): void {
        Http::preventStrayRequests();
        Http::fake([
            'pixelforge.test/v1/generations' => Http::response(
                $this->errorBody('rate_limit_error', $code, 'Slow down.'),
                429,
                $retryAfter === null ? [] : ['Retry-After' => $retryAfter],
            ),
        ]);

        $exception = $this->createGenerationFailure();

        $this->assertInstanceOf(PixelforgeRateLimited::class, $exception);
        $this->assertSame($code, $exception->errorCode);
        $this->assertSame($retryAfterSeconds, $exception->retryAfterSeconds);
    }

    #[Test]
    public function itReportsAnOverloadWithItsRetryAfter(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'pixelforge.test/v1/generations' => Http::response(
                $this->errorBody('api_error', 'overloaded', 'Pixelforge is temporarily overloaded.'),
                503,
                ['Retry-After' => '2'],
            ),
        ]);

        $exception = $this->createGenerationFailure();

        $this->assertInstanceOf(PixelforgeOverloaded::class, $exception);
        $this->assertSame(2, $exception->retryAfterSeconds);
    }

    #[Test]
    #[DataProvider('definitiveErrors')]
    public function itReportsADefinitiveErrorWithPixelforgesCodeAndMessage(int $status, string $code): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'pixelforge.test/v1/generations' => Http::response(
                $this->errorBody('invalid_request_error', $code, 'Pixelforge said no.'),
                $status,
            ),
        ]);

        $exception = $this->createGenerationFailure();

        $this->assertInstanceOf(PixelforgeRejected::class, $exception);
        $this->assertSame($code, $exception->errorCode);
        $this->assertSame('Pixelforge said no.', $exception->getMessage());
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function definitiveErrors(): array
    {
        return [
            'content policy' => [400, 'content_policy_violation'],
            'daily limit' => [429, 'daily_limit_exceeded'],
            'validation' => [422, 'validation_failed'],
            'idempotency key reused' => [409, 'idempotency_key_reused'],
            'invalid api key' => [401, 'invalid_api_key'],
            'unknown 4xx' => [418, 'a_code_pixelforge_adds_later'],
        ];
    }

    #[Test]
    public function itReportsA4xxWithoutAnErrorBodyAsADefinitiveUnknownError(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'pixelforge.test/v1/generations' => Http::response('Forbidden', 403),
        ]);

        $exception = $this->createGenerationFailure();

        $this->assertInstanceOf(PixelforgeRejected::class, $exception);
        $this->assertSame('unknown', $exception->errorCode);
    }

    #[Test]
    #[TestWith([500, ['error' => ['code' => 'internal_error']]])]
    #[TestWith([503, ['error' => ['code' => 'maintenance']]])]
    #[TestWith([502, 'Bad gateway'])]
    #[TestWith([202, 'not json'])]
    #[TestWith([202, ['id' => 'gen_without_the_other_fields']])]
    public function itReportsAnUncertainOutcomeAsUnavailable(int $status, string|array $body): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'pixelforge.test/v1/generations' => Http::response($body, $status),
        ]);

        $exception = $this->createGenerationFailure();

        $this->assertInstanceOf(PixelforgeUnavailable::class, $exception);
    }

    #[Test]
    public function itReportsAFailedConnectionWhileCreatingAsUnavailable(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'pixelforge.test/v1/generations' => Http::failedConnection(),
        ]);

        $exception = $this->createGenerationFailure();

        $this->assertInstanceOf(PixelforgeUnavailable::class, $exception);
    }

    #[Test]
    public function itRetrievesASucceededGenerationWithItsUsageImageAndTimes(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'pixelforge.test/v1/generations/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K' => Http::response(
                FakePixelforgeApi::generationObject(self::GENERATION_ID, GenerationStatus::Succeeded),
            ),
        ]);

        $generation = $this->client()->retrieveGeneration('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K');

        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertEquals(new TokenCountsData(12, 765, 6_450, 3_184), $generation->usage);
        $this->assertStringStartsWith('https://pixelforge.promptedit.dev/images/', (string) $generation->imageUrl);
        $this->assertSame('2026-11-05T14:03:29+00:00', $generation->imageUrlExpiresAt?->toIso8601String());
        $this->assertSame('2026-11-04T14:03:13+00:00', $generation->startedAt?->toIso8601String());
        $this->assertSame('2026-11-04T14:03:29+00:00', $generation->completedAt?->toIso8601String());
        $this->assertNull($generation->errorCode);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->header('Authorization') === ['Bearer test-api-key']);
    }

    #[Test]
    public function itRetrievesAFailedGenerationWithPixelforgesErrorCodeAndMessage(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'pixelforge.test/v1/generations/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K' => Http::response(
                FakePixelforgeApi::generationObject(self::GENERATION_ID, GenerationStatus::Failed),
            ),
        ]);

        $generation = $this->client()->retrieveGeneration('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K');

        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('internal_error', $generation->errorCode);
        $this->assertStringStartsWith('Something went wrong on our side', (string) $generation->errorMessage);
        $this->assertNull($generation->usage);
    }

    #[Test]
    public function itReportsASucceededGenerationWithoutUsageAsUnavailable(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'pixelforge.test/v1/generations/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K' => Http::response(
                FakePixelforgeApi::generationObject(
                    self::GENERATION_ID,
                    GenerationStatus::Succeeded,
                    ['usage' => null],
                ),
            ),
        ]);

        $this->expectException(PixelforgeUnavailable::class);

        $this->client()->retrieveGeneration('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K');
    }

    #[Test]
    #[TestWith([404, 'not_found', PixelforgeRejected::class])]
    #[TestWith([429, 'rate_limit_exceeded', PixelforgeRateLimited::class])]
    #[TestWith([500, 'internal_error', PixelforgeUnavailable::class])]
    public function itMapsRetrieveErrorsLikeCreateErrors(int $status, string $code, string $exceptionClass): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'pixelforge.test/v1/generations/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K' => Http::response(
                $this->errorBody('invalid_request_error', $code, 'Pixelforge said no.'),
                $status,
            ),
        ]);

        $this->expectException($exceptionClass);

        $this->client()->retrieveGeneration('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K');
    }

    private function createGenerationFailure(): Throwable
    {
        try {
            $this->client()->createGeneration($this->lighthouseRequest(), self::GENERATION_ID);
        } catch (Throwable $exception) {
            return $exception;
        }

        $this->fail('createGeneration() did not throw.');
    }

    private function lighthouseRequest(): GenerationRequestData
    {
        return new GenerationRequestData(
            'A lighthouse on a cliff at dawn, oil painting',
            ImageSize::Landscape,
            ImageQuality::High,
            ['https://example.com/style.png'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function queuedGenerationBody(): array
    {
        return [
            'id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
            'object' => 'generation',
            'status' => 'queued',
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style.png'],
            'metadata' => ['generation_id' => self::GENERATION_ID],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'error' => null,
            'created_at' => '2026-11-04T14:03:11Z',
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    /**
     * @return array{error: array{type: string, code: string, message: string}}
     */
    private function errorBody(string $type, string $code, string $message): array
    {
        return ['error' => ['type' => $type, 'code' => $code, 'message' => $message]];
    }

    private function client(): HttpPixelforgeClient
    {
        return new HttpPixelforgeClient(
            $this->app->make(HttpFactory::class),
            baseUrl: 'https://pixelforge.test/v1',
            apiKey: 'test-api-key',
            connectTimeoutSeconds: 10,
            timeoutSeconds: 30,
        );
    }
}
