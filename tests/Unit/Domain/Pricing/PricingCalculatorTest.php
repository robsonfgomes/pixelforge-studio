<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Pricing;

use App\Domain\Generations\Data\GenerationRequestData;
use App\Domain\Generations\Enums\ImageQuality;
use App\Domain\Generations\Enums\ImageSize;
use App\Domain\Pricing\Data\PriceTableData;
use App\Domain\Pricing\Data\TokenCountsData;
use App\Domain\Pricing\PricingCalculator;
use App\Domain\Pricing\ValueObjects\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakePixelforgeApi;

class PricingCalculatorTest extends TestCase
{
    #[Test]
    #[DataProvider('documentedUsages')]
    public function itMatchesTheProviderCostOfTheDocumentedExamples(TokenCountsData $usage, string $providerCost): void
    {
        $rates = FakePixelforgeApi::documentedPriceTable()->rates;

        $cost = $this->calculator()->providerCost($usage, $rates);

        $this->assertSame($providerCost, $cost->toDecimalString());
    }

    /**
     * @return array<string, array{TokenCountsData, string}>
     */
    public static function documentedUsages(): array
    {
        return [
            'the most a generation can cost' => [new TokenCountsData(115, 1_530, 6_450, 8_000), '0.373875000'],
            'the usage report day' => [new TokenCountsData(41, 0, 3_300, 2_950), '0.169080000'],
            'the succeeded lighthouse generation' => [new TokenCountsData(12, 765, 6_450, 3_184), '0.305510000'],
        ];
    }

    #[Test]
    public function itChargesTheProviderCostPlusTheMarkup(): void
    {
        $lighthouseProviderCost = new Money(305_510_000);

        $charge = $this->calculator()->charge($lighthouseProviderCost);

        $this->assertSame('0.381887500', $charge->toDecimalString());
    }

    #[Test]
    public function itRoundsTheChargeUpToTheNanodollar(): void
    {
        $charge = $this->calculator()->charge(new Money(1));

        $this->assertSame(2, $charge->nanodollars);
    }

    #[Test]
    public function itEstimatesTheDocumentedWorstCaseAsTheMaximum(): void
    {
        $request = $this->request(str_repeat('a', 400), ImageQuality::High, ImageSize::Landscape, referenceImages: 2);

        $estimate = $this->calculator()->estimate($request, FakePixelforgeApi::documentedPriceTable());

        $this->assertEquals(new TokenCountsData(115, 1_530, 6_450, 8_000), $estimate->maxTokens);
        $this->assertEquals(new TokenCountsData(100, 1_530, 6_450, 4_500), $estimate->typicalTokens);
        $this->assertEquals(new TokenCountsData(85, 1_530, 6_450, 1_000), $estimate->minTokens);
        $this->assertSame('0.467343750', $estimate->maxCharge->toDecimalString());
        $this->assertSame('0.412562500', $estimate->typicalCharge->toDecimalString());
        $this->assertSame('0.357781250', $estimate->minCharge->toDecimalString());
    }

    #[Test]
    #[DataProvider('qualitiesAndSizes')]
    public function itEstimatesOutputAndReasoningTokensForEachQualityAndSize(
        ImageQuality $quality,
        ImageSize $size,
        int $outputTokens,
        int $minReasoningTokens,
        int $typicalReasoningTokens,
        int $maxReasoningTokens,
    ): void {
        $request = $this->request('A lighthouse', $quality, $size);

        $estimate = $this->calculator()->estimate($request, FakePixelforgeApi::documentedPriceTable());

        $this->assertSame(
            [$outputTokens, $outputTokens, $outputTokens],
            [$estimate->minTokens->output, $estimate->typicalTokens->output, $estimate->maxTokens->output],
        );
        $this->assertSame(
            [$minReasoningTokens, $typicalReasoningTokens, $maxReasoningTokens],
            [$estimate->minTokens->reasoning, $estimate->typicalTokens->reasoning, $estimate->maxTokens->reasoning],
        );
    }

    /**
     * @return array<string, array{ImageQuality, ImageSize, int, int, int, int}>
     */
    public static function qualitiesAndSizes(): array
    {
        return [
            'low square' => [ImageQuality::Low, ImageSize::Square, 280, 0, 250, 500],
            'low landscape' => [ImageQuality::Low, ImageSize::Landscape, 420, 0, 250, 500],
            'low portrait' => [ImageQuality::Low, ImageSize::Portrait, 420, 0, 250, 500],
            'medium square' => [ImageQuality::Medium, ImageSize::Square, 1_100, 200, 1_100, 2_000],
            'medium landscape' => [ImageQuality::Medium, ImageSize::Landscape, 1_650, 200, 1_100, 2_000],
            'medium portrait' => [ImageQuality::Medium, ImageSize::Portrait, 1_650, 200, 1_100, 2_000],
            'high square' => [ImageQuality::High, ImageSize::Square, 4_300, 1_000, 4_500, 8_000],
            'high landscape' => [ImageQuality::High, ImageSize::Landscape, 6_450, 1_000, 4_500, 8_000],
            'high portrait' => [ImageQuality::High, ImageSize::Portrait, 6_450, 1_000, 4_500, 8_000],
        ];
    }

    #[Test]
    #[DataProvider('prompts')]
    public function itEstimatesTextTokensFromUnicodeCharactersWithinTheVariance(
        string $prompt,
        int $minTextTokens,
        int $typicalTextTokens,
        int $maxTextTokens,
    ): void {
        $request = $this->request($prompt, ImageQuality::Medium, ImageSize::Square);

        $estimate = $this->calculator()->estimate($request, FakePixelforgeApi::documentedPriceTable());

        $this->assertSame(
            [$minTextTokens, $typicalTextTokens, $maxTextTokens],
            [$estimate->minTokens->inputText, $estimate->typicalTokens->inputText, $estimate->maxTokens->inputText],
        );
    }

    /**
     * @return array<string, array{string, int, int, int}>
     */
    public static function prompts(): array
    {
        return [
            '400 characters' => [str_repeat('a', 400), 85, 100, 115],
            'a partial token rounds up' => ['abcde', 1, 2, 3],
            'one character' => ['a', 0, 1, 2],
            'accented letters count once' => ['ação', 0, 1, 2],
            'emoji count once' => [str_repeat('🌅', 8), 1, 2, 3],
        ];
    }

    #[Test]
    public function itBillsEveryReferenceImage(): void
    {
        $request = $this->request('A lighthouse', ImageQuality::Low, ImageSize::Square, referenceImages: 3);

        $estimate = $this->calculator()->estimate($request, FakePixelforgeApi::documentedPriceTable());

        $this->assertSame(
            [2_295, 2_295, 2_295],
            [$estimate->minTokens->inputImage, $estimate->typicalTokens->inputImage, $estimate->maxTokens->inputImage],
        );
    }

    #[Test]
    public function itRoundsTheTypicalReasoningTokensUp(): void
    {
        $payload = FakePixelforgeApi::documentedPricingPayload();
        $payload['tokens']['reasoning']['low'] = ['min' => 0, 'max' => 501];
        $request = $this->request('A lighthouse', ImageQuality::Low, ImageSize::Square);

        $estimate = $this->calculator()->estimate($request, PriceTableData::fromArray($payload));

        $this->assertSame(251, $estimate->typicalTokens->reasoning);
    }

    #[Test]
    public function itChargesEachReferenceImageAsItsTokensPlusTheMarkup(): void
    {
        $charge = $this->calculator()->referenceImageCharge(FakePixelforgeApi::documentedPriceTable());

        $this->assertSame('0.009562500', $charge->toDecimalString());
    }

    private function calculator(): PricingCalculator
    {
        return new PricingCalculator(markupPercent: 25);
    }

    private function request(
        string $prompt,
        ImageQuality $quality,
        ImageSize $size,
        int $referenceImages = 0,
    ): GenerationRequestData {
        $urls = [];

        for ($number = 1; $number <= $referenceImages; $number++) {
            $urls[] = "https://example.com/style-{$number}.png";
        }

        return new GenerationRequestData($prompt, $size, $quality, $urls);
    }
}
