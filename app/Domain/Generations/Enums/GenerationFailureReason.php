<?php

declare(strict_types=1);

namespace App\Domain\Generations\Enums;

enum GenerationFailureReason: string
{
    case ContentPolicyViolation = 'content_policy_violation';
    case DailyLimitExceeded = 'daily_limit_exceeded';
    case GenerationFailed = 'generation_failed';
    case PixelforgeBusy = 'pixelforge_busy';
    case Other = 'other';

    public static function fromCode(string $code): self
    {
        return self::tryFrom($code) ?? self::Other;
    }

    /**
     * What the user reads. It never names the provider.
     */
    public function message(): string
    {
        return match ($this) {
            self::ContentPolicyViolation => 'Prompt rejected by the content policy',
            self::DailyLimitExceeded => 'Daily capacity reached, try again after 00:00 UTC',
            self::GenerationFailed => "We couldn't create an image for this prompt. Try rewording it.",
            self::PixelforgeBusy => 'The image service is busy right now. Please try again.',
            self::Other => 'Something went wrong while generating. Please try again.',
        };
    }
}
