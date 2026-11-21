<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Data;

use App\Domain\Pricing\ValueObjects\Money;

/**
 * @phpstan-type RatesPerMillionTokens array{
 *     input_text_tokens: string,
 *     input_image_tokens: string,
 *     output_tokens: string,
 *     reasoning_tokens: string,
 * }
 */
final readonly class TokenRatesData
{
    public function __construct(
        public Money $inputText,
        public Money $inputImage,
        public Money $output,
        public Money $reasoning,
    ) {}

    /**
     * @param  RatesPerMillionTokens  $rates
     */
    public static function fromArray(array $rates): self
    {
        return new self(
            Money::fromRatePerMillionTokens($rates['input_text_tokens']),
            Money::fromRatePerMillionTokens($rates['input_image_tokens']),
            Money::fromRatePerMillionTokens($rates['output_tokens']),
            Money::fromRatePerMillionTokens($rates['reasoning_tokens']),
        );
    }

    /**
     * @return RatesPerMillionTokens
     */
    public function toArray(): array
    {
        return [
            'input_text_tokens' => $this->inputText->toRatePerMillionTokens(),
            'input_image_tokens' => $this->inputImage->toRatePerMillionTokens(),
            'output_tokens' => $this->output->toRatePerMillionTokens(),
            'reasoning_tokens' => $this->reasoning->toRatePerMillionTokens(),
        ];
    }
}
