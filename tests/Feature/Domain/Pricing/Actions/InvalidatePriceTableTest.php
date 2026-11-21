<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Pricing\Actions;

use App\Domain\Pricing\Actions\GetPriceTable;
use App\Domain\Pricing\Actions\InvalidatePriceTable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\FakePixelforgeApi;
use Tests\TestCase;

class InvalidatePriceTableTest extends TestCase
{
    #[Test]
    public function itMakesTheNextLookupFetchAFreshTable(): void
    {
        $pixelforge = FakePixelforgeApi::withDocumentedPrices();
        $getPriceTable = $this->getPriceTable($pixelforge);
        $getPriceTable->handle();

        $this->invalidatePriceTable()->handle();
        $getPriceTable->handle();

        $this->assertSame(2, $pixelforge->pricingRequests);
    }

    #[Test]
    public function itKeepsTheLastKnownTableAsTheFallback(): void
    {
        $pixelforge = FakePixelforgeApi::withDocumentedPrices();
        $getPriceTable = $this->getPriceTable($pixelforge);
        $getPriceTable->handle();
        $pixelforge->makePricingUnavailable();

        $this->invalidatePriceTable()->handle();
        $table = $getPriceTable->handle();

        $this->assertEquals(FakePixelforgeApi::documentedPriceTable(), $table);
    }

    private function getPriceTable(FakePixelforgeApi $pixelforge): GetPriceTable
    {
        return new GetPriceTable($pixelforge, $this->app->make(CacheRepository::class), cacheTtlSeconds: 3_600);
    }

    private function invalidatePriceTable(): InvalidatePriceTable
    {
        return new InvalidatePriceTable($this->app->make(CacheRepository::class));
    }
}
