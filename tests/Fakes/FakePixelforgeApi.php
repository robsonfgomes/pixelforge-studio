<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Pricing\Data\PriceTableData;
use App\Integrations\Pixelforge\Exceptions\PixelforgeUnavailable;
use App\Integrations\Pixelforge\PixelforgeApi;

final class FakePixelforgeApi implements PixelforgeApi
{
    public int $pricingRequests = 0;

    private bool $pricingIsUnavailable = false;

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

    public function makePricingUnavailable(): void
    {
        $this->pricingIsUnavailable = true;
    }

    public function pricing(): PriceTableData
    {
        $this->pricingRequests++;

        if ($this->pricingIsUnavailable) {
            throw PixelforgeUnavailable::unexpectedStatus(503);
        }

        return $this->priceTable;
    }
}
