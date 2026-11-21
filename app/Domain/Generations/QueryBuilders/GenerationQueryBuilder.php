<?php

declare(strict_types=1);

namespace App\Domain\Generations\QueryBuilders;

use App\Domain\Generations\Enums\GenerationStatus;
use App\Domain\Generations\Models\Generation;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<Generation>
 */
class GenerationQueryBuilder extends Builder
{
    public function forUser(User $user): static
    {
        return $this->where('user_id', $user->getKey());
    }

    public function inProgress(): static
    {
        return $this->whereIn('status', [GenerationStatus::Queued->value, GenerationStatus::Processing->value]);
    }

    /**
     * In progress and created since the cutoff; older ones are no longer being worked on by our jobs.
     */
    public function recentlyActive(DateTimeInterface $createdSince): static
    {
        return $this->inProgress()->where('created_at', '>=', $createdSince);
    }
}
