<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Generations\Data;

use App\Domain\Generations\Data\ProviderGenerationData;
use App\Domain\Generations\Enums\GenerationStatus;
use App\Domain\Generations\Exceptions\InvalidProviderGeneration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakePixelforgeApi;

class ProviderGenerationDataTest extends TestCase
{
    #[Test]
    public function itRejectsASucceededGenerationWithoutUsage(): void
    {
        $generation = FakePixelforgeApi::generationObject(
            '019a4f2e-7c1d-7b3a-9e55-2d8f1b4e7a10',
            GenerationStatus::Succeeded,
            ['usage' => null],
        );

        $this->expectException(InvalidProviderGeneration::class);

        ProviderGenerationData::fromArray($generation);
    }
}
