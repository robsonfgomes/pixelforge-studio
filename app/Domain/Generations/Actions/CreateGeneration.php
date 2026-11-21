<?php

declare(strict_types=1);

namespace App\Domain\Generations\Actions;

use App\Domain\Generations\Data\GenerationRequestData;
use App\Domain\Generations\Enums\GenerationStatus;
use App\Domain\Generations\Models\Generation;
use App\Domain\Pricing\Actions\GetPriceTable;
use App\Domain\Pricing\Exceptions\PriceTableUnavailable;
use App\Domain\Pricing\PricingCalculator;
use App\Jobs\SubmitGenerationJob;
use App\Models\User;

final readonly class CreateGeneration
{
    public function __construct(
        private GetPriceTable $getPriceTable,
        private PricingCalculator $calculator,
    ) {}

    /**
     * @throws PriceTableUnavailable
     */
    public function handle(User $user, GenerationRequestData $request): Generation
    {
        $priceTable = $this->getPriceTable->handle();
        $estimate = $this->calculator->estimate($request, $priceTable);

        $generation = Generation::query()->create([
            'user_id' => $user->getKey(),
            'status' => GenerationStatus::Queued,
            'prompt' => $request->prompt,
            'size' => $request->size,
            'quality' => $request->quality,
            'reference_images' => $request->referenceImages,
            'estimate_price_table' => $priceTable->toArray(),
            'estimated_charge' => $estimate->typicalCharge,
            'estimated_max_charge' => $estimate->maxCharge,
        ]);

        SubmitGenerationJob::dispatch($generation->id);

        return $generation;
    }
}
