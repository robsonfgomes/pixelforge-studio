<?php

declare(strict_types=1);

namespace App\Domain\Generations\Enums;

enum GenerationFailureReason: string
{
    case ContentPolicyViolation = 'content_policy_violation';
    case DailyLimitExceeded = 'daily_limit_exceeded';
    case PixelforgeBusy = 'pixelforge_busy';
    case Other = 'other';

    public static function fromCode(string $code): self
    {
        return self::tryFrom($code) ?? self::Other;
    }

    public function message(): string
    {
        return match ($this) {
            self::ContentPolicyViolation => 'Prompt rejected by the content policy',
            self::DailyLimitExceeded => 'Daily capacity reached, try again after 00:00 UTC',
            self::PixelforgeBusy => 'Pixelforge is busy. Please try again.',
            self::Other => 'Something went wrong while generating. Please try again.',
        };
    }
}
