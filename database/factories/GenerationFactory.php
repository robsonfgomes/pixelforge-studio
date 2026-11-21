<?php

namespace Database\Factories;

use App\Domain\Generations\Enums\GenerationStatus;
use App\Domain\Generations\Enums\ImageQuality;
use App\Domain\Generations\Enums\ImageSize;
use App\Domain\Generations\Models\Generation;
use App\Domain\Pricing\ValueObjects\Money;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * The rates and token table Pixelforge documents for `GET /v1/pricing`.
     */
    private const array DOCUMENTED_PRICE_TABLE = [
        'rates' => [
            'input_text_tokens' => '5.000',
            'input_image_tokens' => '10.000',
            'output_tokens' => '40.000',
            'reasoning_tokens' => '12.500',
        ],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => GenerationStatus::Queued,
            'prompt' => fake()->sentence(),
            'size' => ImageSize::Square,
            'quality' => ImageQuality::Medium,
            'reference_images' => [],
            'estimate_price_table' => self::DOCUMENTED_PRICE_TABLE,
            'estimated_charge' => new Money(70_137_500),
            'estimated_max_charge' => new Money(87_500_000),
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (): array => [
            'pixelforge_id' => 'gen_'.Str::upper(Str::random(26)),
            'rates' => self::DOCUMENTED_PRICE_TABLE['rates'],
            'submitted_at' => now(),
        ]);
    }

    public function uncertainSince(DateTimeInterface $firstUncertainOutcomeAt): static
    {
        return $this->state(fn (): array => [
            'submission_uncertain_at' => $firstUncertainOutcomeAt,
        ]);
    }

    public function failed(string $errorCode, string $errorMessage): static
    {
        return $this->state(fn (): array => [
            'status' => GenerationStatus::Failed,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'provider_cost' => Money::zero(),
            'charge' => Money::zero(),
        ]);
    }
}
