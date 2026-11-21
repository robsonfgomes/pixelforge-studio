<?php

declare(strict_types=1);

namespace App\Domain\Generations\Data;

use App\Domain\Generations\Enums\ImageQuality;
use App\Domain\Generations\Enums\ImageSize;

final readonly class GenerationRequestData
{
    /**
     * @param  list<string>  $referenceImages
     */
    public function __construct(
        public string $prompt,
        public ImageSize $size,
        public ImageQuality $quality,
        public array $referenceImages,
    ) {}
}
