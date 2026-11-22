<?php

declare(strict_types=1);

namespace App\Domain\Generations\Models;

use App\Domain\Generations\Enums\GenerationStatus;
use App\Domain\Generations\Enums\ImageQuality;
use App\Domain\Generations\Enums\ImageSize;
use App\Domain\Generations\QueryBuilders\GenerationQueryBuilder;
use App\Domain\Pricing\Casts\MoneyCast;
use App\Domain\Pricing\ValueObjects\Money;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int|null $user_id
 * @property GenerationStatus $status
 * @property string $prompt
 * @property ImageSize $size
 * @property ImageQuality $quality
 * @property list<string> $reference_images
 * @property array<string, mixed> $estimate_price_table
 * @property Money $estimated_charge
 * @property Money $estimated_max_charge
 * @property string|null $pixelforge_id
 * @property array<string, string>|null $rates
 * @property int|null $input_text_tokens
 * @property int|null $input_image_tokens
 * @property int|null $output_tokens
 * @property int|null $reasoning_tokens
 * @property Money|null $provider_cost
 * @property Money|null $charge
 * @property string|null $image_url
 * @property CarbonImmutable|null $image_url_expires_at
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int $submit_attempts
 * @property string|null $last_submit_error_code
 * @property CarbonImmutable|null $submission_uncertain_at
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $user
 *
 * @method static GenerationQueryBuilder query()
 */
#[UseEloquentBuilder(GenerationQueryBuilder::class)]
#[UseFactory(GenerationFactory::class)]
#[Fillable([
    'user_id',
    'status',
    'prompt',
    'size',
    'quality',
    'reference_images',
    'estimate_price_table',
    'estimated_charge',
    'estimated_max_charge',
])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUuids;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'size' => ImageSize::class,
            'quality' => ImageQuality::class,
            'reference_images' => 'array',
            'estimate_price_table' => 'array',
            'estimated_charge' => MoneyCast::class,
            'estimated_max_charge' => MoneyCast::class,
            'rates' => 'array',
            'provider_cost' => MoneyCast::class,
            'charge' => MoneyCast::class,
            'submit_attempts' => 'integer',
            'image_url_expires_at' => 'datetime',
            'submission_uncertain_at' => 'datetime',
            'submitted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
