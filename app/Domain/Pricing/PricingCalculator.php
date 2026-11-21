<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Generations\Data\GenerationRequestData;
use App\Domain\Pricing\Data\CostEstimateData;
use App\Domain\Pricing\Data\PriceTableData;
use App\Domain\Pricing\Data\TokenCountsData;
use App\Domain\Pricing\Data\TokenRatesData;
use App\Domain\Pricing\ValueObjects\Money;

final readonly class PricingCalculator
{
    private const int BASIS_POINTS = 10_000;

    public function __construct(private int $markupPercent) {}

    public function providerCost(TokenCountsData $tokens, TokenRatesData $rates): Money
    {
        return $rates->inputText->times($tokens->inputText)
            ->plus($rates->inputImage->times($tokens->inputImage))
            ->plus($rates->output->times($tokens->output))
            ->plus($rates->reasoning->times($tokens->reasoning));
    }

    public function charge(Money $providerCost): Money
    {
        return $providerCost->withMarkupPercent($this->markupPercent);
    }

    public function estimate(GenerationRequestData $request, PriceTableData $table): CostEstimateData
    {
        $estimatedTextTokens = $this->estimatedTextTokens($request->prompt, $table);
        $variance = $table->textTokenVarianceBasisPoints;
        $imageTokens = count($request->referenceImages) * $table->tokensPerReferenceImage;
        $outputTokens = $table->outputTokensFor($request->quality, $request->size);
        $minReasoningTokens = $table->minReasoningTokensFor($request->quality);
        $maxReasoningTokens = $table->maxReasoningTokensFor($request->quality);

        $minTokens = new TokenCountsData(
            intdiv($estimatedTextTokens * (self::BASIS_POINTS - $variance), self::BASIS_POINTS),
            $imageTokens,
            $outputTokens,
            $minReasoningTokens,
        );
        $typicalTokens = new TokenCountsData(
            $estimatedTextTokens,
            $imageTokens,
            $outputTokens,
            intdiv($minReasoningTokens + $maxReasoningTokens + 1, 2),
        );
        $maxTokens = new TokenCountsData(
            self::divideRoundingUp($estimatedTextTokens * (self::BASIS_POINTS + $variance), self::BASIS_POINTS),
            $imageTokens,
            $outputTokens,
            $maxReasoningTokens,
        );

        return new CostEstimateData(
            $minTokens,
            $typicalTokens,
            $maxTokens,
            $this->charge($this->providerCost($minTokens, $table->rates)),
            $this->charge($this->providerCost($typicalTokens, $table->rates)),
            $this->charge($this->providerCost($maxTokens, $table->rates)),
        );
    }

    private function estimatedTextTokens(string $prompt, PriceTableData $table): int
    {
        return self::divideRoundingUp(mb_strlen($prompt), $table->charactersPerTextToken);
    }

    private static function divideRoundingUp(int $dividend, int $divisor): int
    {
        return intdiv($dividend + $divisor - 1, $divisor);
    }
}
