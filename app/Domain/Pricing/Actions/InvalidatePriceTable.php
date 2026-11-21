<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

final readonly class InvalidatePriceTable
{
    public function __construct(private CacheRepository $cache) {}

    public function handle(): void
    {
        $this->cache->forget(GetPriceTable::CURRENT_CACHE_KEY);
    }
}
