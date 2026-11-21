<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Pricing\Data\CostEstimateData;
use App\Domain\Pricing\Enums\DisplayRounding;
use App\Domain\Pricing\ValueObjects\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property CostEstimateData $resource
 */
class CostEstimateResource extends JsonResource
{
    /**
     * @return array<string, array{amount: string, display: string}>
     */
    public function toArray(Request $request): array
    {
        return [
            'min' => $this->amount($this->resource->minCharge, DisplayRounding::Down),
            'typical' => $this->amount($this->resource->typicalCharge, DisplayRounding::HalfUp),
            'max' => $this->amount($this->resource->maxCharge, DisplayRounding::Up),
        ];
    }

    /**
     * @return array{amount: string, display: string}
     */
    private function amount(Money $money, DisplayRounding $rounding): array
    {
        return ['amount' => $money->toDecimalString(), 'display' => $money->format($rounding)];
    }
}
