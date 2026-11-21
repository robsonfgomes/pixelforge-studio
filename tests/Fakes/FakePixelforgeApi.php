<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Generations\Data\GenerationRequestData;
use App\Domain\Generations\Data\ProviderGenerationData;
use App\Domain\Generations\Enums\GenerationStatus;
use App\Domain\Pricing\Data\PriceTableData;
use App\Domain\Pricing\Data\TokenRatesData;
use App\Integrations\Pixelforge\Exceptions\PixelforgeUnavailable;
use App\Integrations\Pixelforge\PixelforgeApi;
use Closure;
use LogicException;
use Throwable;

final class FakePixelforgeApi implements PixelforgeApi
{
    public const string PIXELFORGE_ID = 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K';

    public int $pricingRequests = 0;

    /**
     * @var list<array{request: GenerationRequestData, generationId: string}>
     */
    public array $createRequests = [];

    /**
     * @var list<string>
     */
    public array $retrieveRequests = [];

    private bool $pricingIsUnavailable = false;

    /**
     * @var list<ProviderGenerationData|Throwable>
     */
    private array $createResponses = [];

    /**
     * @var list<ProviderGenerationData|Throwable|Closure(): ProviderGenerationData>
     */
    private array $retrieveResponses = [];

    public function __construct(private readonly PriceTableData $priceTable) {}

    public static function withDocumentedPrices(): self
    {
        return new self(self::documentedPriceTable());
    }

    public static function documentedPriceTable(): PriceTableData
    {
        return PriceTableData::fromArray(self::documentedPricingPayload());
    }

    /**
     * @return array<string, mixed>
     */
    public static function documentedPricingPayload(): array
    {
        $json = (string) file_get_contents(dirname(__DIR__).'/Fixtures/Pixelforge/pricing.json');

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * A Pixelforge generation object shaped like the documented lighthouse example, in the given status.
     *
     * @param  array<string, mixed>  $overrides  top-level fields to replace
     * @return array<string, mixed>
     */
    public static function generationObject(
        string $generationId,
        GenerationStatus $status,
        array $overrides = [],
    ): array {
        $queued = [
            'id' => self::PIXELFORGE_ID,
            'object' => 'generation',
            'status' => $status->value,
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style.png'],
            'metadata' => ['generation_id' => $generationId],
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
        $started = ['started_at' => '2026-11-04T14:03:13Z'];
        $completed = [...$started, 'completed_at' => '2026-11-04T14:03:29Z'];

        $object = match ($status) {
            GenerationStatus::Queued => $queued,
            GenerationStatus::Processing => [...$queued, ...$started],
            GenerationStatus::Succeeded => [...$queued, ...$completed,
                'image_url' => 'https://pixelforge.promptedit.dev/images/'.self::PIXELFORGE_ID.'.png?signature=abc',
                'image_url_expires_at' => '2026-11-05T14:03:29Z',
                'usage' => [
                    'input_text_tokens' => 12,
                    'input_image_tokens' => 765,
                    'output_tokens' => 6450,
                    'reasoning_tokens' => 3184,
                    'total_tokens' => 10411,
                ],
            ],
            GenerationStatus::Failed => [...$queued, ...$completed,
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'Something went wrong on our side while generating. '
                        .'You were not charged; please retry.',
                ],
            ],
        };

        return array_replace($object, $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides  top-level fields to replace
     */
    public static function providerGeneration(
        string $generationId,
        GenerationStatus $status,
        array $overrides = [],
    ): ProviderGenerationData {
        return ProviderGenerationData::fromArray(self::generationObject($generationId, $status, $overrides));
    }

    /**
     * A webhook event body. Generation events carry the full generation object; `ping` carries none.
     *
     * @param  array<string, mixed>|null  $generationObject
     * @return array<string, mixed>
     */
    public static function webhookEvent(string $eventId, string $type, ?array $generationObject = null): array
    {
        return [
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'created_at' => '2026-11-04T14:03:29Z',
            'data' => $generationObject === null ? [] : ['object' => $generationObject],
        ];
    }

    public static function acceptedGeneration(
        string $generationId,
        string $pixelforgeId = self::PIXELFORGE_ID,
        ?TokenRatesData $rates = null,
    ): ProviderGenerationData {
        return self::providerGeneration($generationId, GenerationStatus::Queued, [
            'id' => $pixelforgeId,
            'rates' => ($rates ?? self::documentedPriceTable()->rates)->toArray(),
        ]);
    }

    public function makePricingUnavailable(): void
    {
        $this->pricingIsUnavailable = true;
    }

    /**
     * Each call to createGeneration() takes the next response; once they run out, it accepts the generation.
     */
    public function respondToCreateWith(ProviderGenerationData|Throwable ...$responses): void
    {
        $this->createResponses = [...$this->createResponses, ...array_values($responses)];
    }

    /**
     * Each call to retrieveGeneration() takes the next response. A closure runs during the call, so a test can make
     * something happen while the request is in flight.
     */
    public function respondToRetrieveWith(ProviderGenerationData|Throwable|Closure ...$responses): void
    {
        $this->retrieveResponses = [...$this->retrieveResponses, ...array_values($responses)];
    }

    public function pricing(): PriceTableData
    {
        $this->pricingRequests++;

        if ($this->pricingIsUnavailable) {
            throw PixelforgeUnavailable::unexpectedStatus(503);
        }

        return $this->priceTable;
    }

    public function createGeneration(GenerationRequestData $request, string $generationId): ProviderGenerationData
    {
        $this->createRequests[] = ['request' => $request, 'generationId' => $generationId];
        $response = array_shift($this->createResponses) ?? self::acceptedGeneration($generationId);

        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }

    public function retrieveGeneration(string $pixelforgeId): ProviderGenerationData
    {
        $this->retrieveRequests[] = $pixelforgeId;
        $response = array_shift($this->retrieveResponses)
            ?? throw new LogicException('No retrieveGeneration() response was queued.');

        if ($response instanceof Closure) {
            $response = $response();
        }

        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }
}
