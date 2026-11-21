<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Pricing\Data;

use App\Domain\Generations\Enums\ImageQuality;
use App\Domain\Generations\Enums\ImageSize;
use App\Domain\Pricing\Data\PriceTableData;
use App\Domain\Pricing\Exceptions\UnsupportedRatePrecision;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakePixelforgeApi;

class PriceTableDataTest extends TestCase
{
    #[Test]
    public function itConvertsTheDocumentedPayloadToIntegers(): void
    {
        $payload = FakePixelforgeApi::documentedPricingPayload();

        $table = PriceTableData::fromArray($payload);

        $this->assertSame(5_000, $table->rates->inputText->nanodollars);
        $this->assertSame(10_000, $table->rates->inputImage->nanodollars);
        $this->assertSame(40_000, $table->rates->output->nanodollars);
        $this->assertSame(12_500, $table->rates->reasoning->nanodollars);
        $this->assertSame(4, $table->charactersPerTextToken);
        $this->assertSame(1_500, $table->textTokenVarianceBasisPoints);
        $this->assertSame(765, $table->tokensPerReferenceImage);
        $this->assertSame(6_450, $table->outputTokensFor(ImageQuality::High, ImageSize::Landscape));
        $this->assertSame(200, $table->minReasoningTokensFor(ImageQuality::Medium));
        $this->assertSame(2_000, $table->maxReasoningTokensFor(ImageQuality::Medium));
    }

    #[Test]
    public function itRestoresTheSameTableFromItsArray(): void
    {
        $table = FakePixelforgeApi::documentedPriceTable();

        $restored = PriceTableData::fromArray($table->toArray());

        $this->assertEquals($table, $restored);
    }

    #[Test]
    public function itRejectsARateWithMoreThanThreeDecimals(): void
    {
        $payload = FakePixelforgeApi::documentedPricingPayload();
        $payload['rates']['output_tokens'] = '40.0001';

        $this->expectException(UnsupportedRatePrecision::class);

        PriceTableData::fromArray($payload);
    }
}
