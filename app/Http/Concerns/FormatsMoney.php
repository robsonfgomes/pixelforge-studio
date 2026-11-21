<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use App\Domain\Pricing\Enums\DisplayRounding;
use App\Domain\Pricing\ValueObjects\Money;

trait FormatsMoney
{
    /**
     * The exact amount (for the tooltip) and the value to display, so the frontend never rounds money.
     *
     * @return array{amount: string, display: string}
     */
    private function amount(Money $money, DisplayRounding $rounding): array
    {
        return ['amount' => $money->toDecimalString(), 'display' => $money->format($rounding)];
    }
}
