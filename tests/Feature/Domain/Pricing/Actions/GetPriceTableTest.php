<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Pricing\Actions;

use App\Domain\Pricing\Actions\GetPriceTable;
use App\Domain\Pricing\Exceptions\PriceTableUnavailable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\FakePixelforgeApi;
use Tests\TestCase;

class GetPriceTableTest extends TestCase
{
    #[Test]
    public function itFetchesThePriceTableOnceWhileTheCacheIsFresh(): void
    {
        $pixelforge = FakePixelforgeApi::withDocumentedPrices();
        $getPriceTable = $this->getPriceTable($pixelforge);

        $first = $getPriceTable->handle();
        $second = $getPriceTable->handle();

        $this->assertSame(1, $pixelforge->pricingRequests);
        $this->assertEquals(FakePixelforgeApi::documentedPriceTable(), $first);
        $this->assertEquals($first, $second);
    }

    #[Test]
    public function itFetchesAgainOnceTheCacheExpires(): void
    {
        $this->freezeTime();
        $pixelforge = FakePixelforgeApi::withDocumentedPrices();
        $getPriceTable = $this->getPriceTable($pixelforge);
        $getPriceTable->handle();

        $this->travel(3_601)->seconds();
        $getPriceTable->handle();

        $this->assertSame(2, $pixelforge->pricingRequests);
    }

    #[Test]
    public function itFallsBackToTheLastKnownTableWhenPixelforgeIsUnavailable(): void
    {
        $this->freezeTime();
        $pixelforge = FakePixelforgeApi::withDocumentedPrices();
        $getPriceTable = $this->getPriceTable($pixelforge);
        $getPriceTable->handle();
        $this->travel(3_601)->seconds();
        $pixelforge->makePricingUnavailable();

        $table = $getPriceTable->handle();

        $this->assertEquals(FakePixelforgeApi::documentedPriceTable(), $table);
        $this->assertSame(2, $pixelforge->pricingRequests);
    }

    #[Test]
    public function itFailsWhenNoTableWasEverFetchedAndPixelforgeIsUnavailable(): void
    {
        $pixelforge = FakePixelforgeApi::withDocumentedPrices();
        $pixelforge->makePricingUnavailable();

        $this->expectException(PriceTableUnavailable::class);

        $this->getPriceTable($pixelforge)->handle();
    }

    private function getPriceTable(FakePixelforgeApi $pixelforge): GetPriceTable
    {
        return new GetPriceTable($pixelforge, $this->app->make(CacheRepository::class), cacheTtlSeconds: 3_600);
    }
}
