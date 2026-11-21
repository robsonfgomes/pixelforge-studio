<?php

declare(strict_types=1);

namespace App\Domain\Generations\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property string|null $generation_id
 * @property int $delivery_attempt
 * @property array<string, mixed> $payload
 * @property CarbonImmutable|null $created_at
 * @property-read Generation|null $generation
 */
class WebhookEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Generation, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivery_attempt' => 'integer',
        ];
    }
}
