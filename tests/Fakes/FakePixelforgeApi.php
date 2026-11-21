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
use Throwable;

final class FakePixelforgeApi implements PixelforgeApi
{
    public int $pricingRequests = 0;

    /**
     * @var list<array{request: GenerationRequestData, generationId: string}>
     */
    public array $createRequests = [];

    private bool $pricingIsUnavailable = false;

    /**
     * @var list<ProviderGenerationData|Throwable>
     */
    private array $createResponses = [];

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

    public static function acceptedGeneration(
        string $generationId,
        string $pixelforgeId = 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
        ?TokenRatesData $rates = null,
    ): ProviderGenerationData {
        return new ProviderGenerationData(
            $pixelforgeId,
            GenerationStatus::Queued,
            $generationId,
            $rates ?? self::documentedPriceTable()->rates,
        );
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
}
