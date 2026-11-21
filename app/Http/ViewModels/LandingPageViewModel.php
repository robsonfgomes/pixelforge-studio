<?php

declare(strict_types=1);

namespace App\Http\ViewModels;

use App\Domain\Generations\Data\GenerationRequestData;
use App\Domain\Generations\Enums\ImageQuality;
use App\Domain\Generations\Enums\ImageSize;
use App\Domain\Pricing\Actions\GetPriceTable;
use App\Domain\Pricing\Enums\DisplayRounding;
use App\Domain\Pricing\Exceptions\PriceTableUnavailable;
use App\Domain\Pricing\PricingCalculator;
use App\Http\Concerns\FormatsMoney;
use Illuminate\Contracts\Support\Arrayable;
use Inertia\Inertia;

/**
 * Props of the landing. The pricing example is deferred, so a slow price table never delays the page.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class LandingPageViewModel implements Arrayable
{
    use FormatsMoney;

    /**
     * The example prices a typical prompt with no reference images; it is presentation, not a business rule.
     */
    private const int EXAMPLE_PROMPT_LENGTH = 200;

    public function __construct(
        private GetPriceTable $getPriceTable,
        private PricingCalculator $calculator,
        private int $markupPercent,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'markupPercent' => $this->markupPercent,
            'pricingExample' => Inertia::defer(fn (): array => $this->pricingExample()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pricingExample(): array
    {
        try {
            $priceTable = $this->getPriceTable->handle();
        } catch (PriceTableUnavailable) {
            return ['available' => false];
        }

        $rows = array_map(function (ImageQuality $quality) use ($priceTable): array {
            $request = new GenerationRequestData(
                str_repeat('a', self::EXAMPLE_PROMPT_LENGTH),
                ImageSize::Square,
                $quality,
                [],
            );
            $estimate = $this->calculator->estimate($request, $priceTable);

            return [
                'quality' => $quality->label(),
                'typical' => $this->amount($estimate->typicalCharge, DisplayRounding::HalfUp),
                'max' => $this->amount($estimate->maxCharge, DisplayRounding::Up),
            ];
        }, ImageQuality::cases());

        return [
            'available' => true,
            'promptLength' => self::EXAMPLE_PROMPT_LENGTH,
            'rows' => $rows,
            'perReferenceImage' => $this->amount(
                $this->calculator->referenceImageCharge($priceTable),
                DisplayRounding::HalfUp,
            ),
        ];
    }
}
