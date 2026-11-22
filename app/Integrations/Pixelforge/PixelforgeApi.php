<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge;

use App\Domain\Generations\Data\GenerationRequestData;
use App\Domain\Generations\Data\ProviderGenerationData;
use App\Domain\Pricing\Data\PriceTableData;
use App\Integrations\Pixelforge\Exceptions\PixelforgeOverloaded;
use App\Integrations\Pixelforge\Exceptions\PixelforgeRateLimited;
use App\Integrations\Pixelforge\Exceptions\PixelforgeRejected;
use App\Integrations\Pixelforge\Exceptions\PixelforgeUnavailable;

interface PixelforgeApi
{
    /**
     * @throws PixelforgeUnavailable
     */
    public function pricing(): PriceTableData;

    /**
     * Creates the generation with our id as the `Idempotency-Key` and as `metadata.generation_id`.
     *
     * @throws PixelforgeRateLimited nothing was created
     * @throws PixelforgeOverloaded nothing was created
     * @throws PixelforgeRejected nothing was created, and retrying won't help
     * @throws PixelforgeUnavailable the outcome is unknown: Pixelforge may have created it
     */
    public function createGeneration(GenerationRequestData $request, string $generationId): ProviderGenerationData;

    /**
     * @throws PixelforgeRateLimited
     * @throws PixelforgeOverloaded
     * @throws PixelforgeRejected e.g. `not_found`
     * @throws PixelforgeUnavailable
     */
    public function retrieveGeneration(string $pixelforgeId): ProviderGenerationData;
}
