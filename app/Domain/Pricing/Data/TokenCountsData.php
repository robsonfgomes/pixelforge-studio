<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Data;

final readonly class TokenCountsData
{
    public function __construct(
        public int $inputText,
        public int $inputImage,
        public int $output,
        public int $reasoning,
    ) {}
}
