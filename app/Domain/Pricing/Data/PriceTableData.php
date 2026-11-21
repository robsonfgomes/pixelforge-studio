<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Data;

use App\Domain\Generations\Enums\ImageQuality;
use App\Domain\Generations\Enums\ImageSize;

final readonly class PriceTableData
{
    private const int BASIS_POINTS = 10_000;

    /**
     * @param  array<string, array<string, int>>  $outputTokens  tokens by quality, then by size
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  token range by quality
     */
    public function __construct(
        public TokenRatesData $rates,
        public int $charactersPerTextToken,
        public int $textTokenVarianceBasisPoints,
        public int $tokensPerReferenceImage,
        private array $outputTokens,
        private array $reasoningTokens,
    ) {}

    /**
     * Builds the table from the `GET /v1/pricing` body. The variance arrives as a JSON number (0.15) and is kept as
     * integer basis points (1,500) so the estimate never does float arithmetic.
     *
     * @param  array<string, mixed>  $pricing
     */
    public static function fromArray(array $pricing): self
    {
        $tokens = $pricing['tokens'];

        return new self(
            TokenRatesData::fromArray($pricing['rates']),
            (int) $tokens['characters_per_text_token'],
            (int) round($tokens['text_token_variance'] * self::BASIS_POINTS),
            (int) $tokens['per_reference_image'],
            $tokens['output'],
            $tokens['reasoning'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rates' => $this->rates->toArray(),
            'tokens' => [
                'characters_per_text_token' => $this->charactersPerTextToken,
                'text_token_variance' => $this->textTokenVarianceBasisPoints / self::BASIS_POINTS,
                'per_reference_image' => $this->tokensPerReferenceImage,
                'output' => $this->outputTokens,
                'reasoning' => $this->reasoningTokens,
            ],
        ];
    }

    public function outputTokensFor(ImageQuality $quality, ImageSize $size): int
    {
        return $this->outputTokens[$quality->value][$size->value];
    }

    public function minReasoningTokensFor(ImageQuality $quality): int
    {
        return $this->reasoningTokens[$quality->value]['min'];
    }

    public function maxReasoningTokensFor(ImageQuality $quality): int
    {
        return $this->reasoningTokens[$quality->value]['max'];
    }
}
