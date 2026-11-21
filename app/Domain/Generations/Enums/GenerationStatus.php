<?php

declare(strict_types=1);

namespace App\Domain\Generations\Enums;

enum GenerationStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /**
     * Statuses only move forward. A generation can skip `processing` because that event may be lost or arrive late.
     */
    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Queued => $next !== self::Queued,
            self::Processing => $next->isFinal(),
            self::Succeeded, self::Failed => false,
        };
    }
}
