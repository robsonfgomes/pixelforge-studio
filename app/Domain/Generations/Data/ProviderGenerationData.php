<?php

declare(strict_types=1);

namespace App\Domain\Generations\Data;

use App\Domain\Generations\Enums\GenerationStatus;
use App\Domain\Pricing\Data\TokenRatesData;

final readonly class ProviderGenerationData
{
    public function __construct(
        public string $id,
        public GenerationStatus $status,
        public ?string $generationId,
        public TokenRatesData $rates,
    ) {}

    /**
     * Builds the data from a Pixelforge generation object; `generationId` is our id from `metadata.generation_id`.
     *
     * @param  array<string, mixed>  $generation
     */
    public static function fromArray(array $generation): self
    {
        return new self(
            $generation['id'],
            GenerationStatus::from($generation['status']),
            $generation['metadata']['generation_id'] ?? null,
            TokenRatesData::fromArray($generation['rates']),
        );
    }
}
