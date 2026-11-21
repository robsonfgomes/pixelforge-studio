<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Pricing\Actions\GetPriceTable;
use App\Domain\Pricing\Exceptions\PriceTableUnavailable;
use App\Domain\Pricing\PricingCalculator;
use App\Http\Requests\EstimateGenerationRequest;
use App\Http\Resources\CostEstimateResource;
use Illuminate\Http\JsonResponse;

class GenerationEstimateController extends Controller
{
    public function __invoke(
        EstimateGenerationRequest $request,
        GetPriceTable $getPriceTable,
        PricingCalculator $calculator,
    ): CostEstimateResource|JsonResponse {
        try {
            $priceTable = $getPriceTable->handle();
        } catch (PriceTableUnavailable) {
            return response()->json(['message' => 'Estimate unavailable'], JsonResponse::HTTP_SERVICE_UNAVAILABLE);
        }

        return new CostEstimateResource($calculator->estimate($request->toData(), $priceTable));
    }
}
