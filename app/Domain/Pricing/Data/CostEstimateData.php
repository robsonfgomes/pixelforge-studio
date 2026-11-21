<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Data;

use App\Domain\Pricing\ValueObjects\Money;

final readonly class CostEstimateData
{
    public function __construct(
        public TokenCountsData $minTokens,
        public TokenCountsData $typicalTokens,
        public TokenCountsData $maxTokens,
        public Money $minCharge,
        public Money $typicalCharge,
        public Money $maxCharge,
    ) {}
}
