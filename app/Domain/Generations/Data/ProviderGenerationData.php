<?php

declare(strict_types=1);

namespace App\Domain\Generations\Data;

use App\Domain\Generations\Enums\GenerationStatus;
use App\Domain\Generations\Exceptions\InvalidProviderGeneration;
use App\Domain\Pricing\Data\TokenCountsData;
use App\Domain\Pricing\Data\TokenRatesData;
use Carbon\CarbonImmutable;

final readonly class ProviderGenerationData
{
    public function __construct(
        public string $id,
        public GenerationStatus $status,
        public ?string $generationId,
        public TokenRatesData $rates,
        public ?TokenCountsData $usage = null,
        public ?string $imageUrl = null,
        public ?CarbonImmutable $imageUrlExpiresAt = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public ?CarbonImmutable $startedAt = null,
        public ?CarbonImmutable $completedAt = null,
    ) {}

    /**
     * Builds the data from a Pixelforge generation object; `generationId` is our id from `metadata.generation_id`.
     *
     * @param  array<string, mixed>  $generation
     */
    public static function fromArray(array $generation): self
    {
        $status = GenerationStatus::from($generation['status']);
        $usage = $generation['usage'] ?? null;

        if ($status === GenerationStatus::Succeeded && ! is_array($usage)) {
            throw InvalidProviderGeneration::succeededWithoutUsage((string) $generation['id']);
        }

        return new self(
            $generation['id'],
            $status,
            $generation['metadata']['generation_id'] ?? null,
            TokenRatesData::fromArray($generation['rates']),
            is_array($usage) ? new TokenCountsData(
                (int) $usage['input_text_tokens'],
                (int) $usage['input_image_tokens'],
                (int) $usage['output_tokens'],
                (int) $usage['reasoning_tokens'],
            ) : null,
            $generation['image_url'] ?? null,
            self::timestamp($generation['image_url_expires_at'] ?? null),
            $generation['error']['code'] ?? null,
            $generation['error']['message'] ?? null,
            self::timestamp($generation['started_at'] ?? null),
            self::timestamp($generation['completed_at'] ?? null),
        );
    }

    private static function timestamp(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value)->utc();
    }
}
