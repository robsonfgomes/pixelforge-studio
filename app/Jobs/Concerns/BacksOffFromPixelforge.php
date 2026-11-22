<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

/**
 * How long a job waits before asking Pixelforge again: never less than its Retry-After, and longer on each attempt,
 * as Pixelforge asks. Meant for queued jobs, whose `attempts()` counts the releases.
 */
trait BacksOffFromPixelforge
{
    private function retryDelaySeconds(?int $retryAfterSeconds, ?string $errorCode = null): int
    {
        $backoff = config('generations.retry_backoff');
        $cap = $errorCode === 'concurrency_limit_exceeded'
            ? $backoff['concurrency_cap_seconds']
            : $backoff['cap_seconds'];
        $exponential = min((int) $cap, (int) $backoff['base_seconds'] * 2 ** max(0, $this->attempts() - 1));
        $delay = max($retryAfterSeconds ?? 0, $exponential);

        return $delay + intdiv($delay * random_int(0, (int) $backoff['max_jitter_percent']), 100);
    }
}
