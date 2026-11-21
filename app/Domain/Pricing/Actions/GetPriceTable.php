<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Domain\Pricing\Data\PriceTableData;
use App\Domain\Pricing\Exceptions\PriceTableUnavailable;
use App\Integrations\Pixelforge\Exceptions\PixelforgeUnavailable;
use App\Integrations\Pixelforge\PixelforgeApi;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;

final readonly class GetPriceTable
{
    public const string CURRENT_CACHE_KEY = 'pixelforge:price-table:current';

    public const string LAST_KNOWN_CACHE_KEY = 'pixelforge:price-table:last-known';

    public function __construct(
        private PixelforgeApi $pixelforge,
        private CacheRepository $cache,
        private int $cacheTtlSeconds,
    ) {}

    /**
     * @throws PriceTableUnavailable
     */
    public function handle(): PriceTableData
    {
        $current = $this->cache->get(self::CURRENT_CACHE_KEY);

        if (is_array($current)) {
            return PriceTableData::fromArray($current);
        }

        try {
            $table = $this->pixelforge->pricing();
        } catch (PixelforgeUnavailable $exception) {
            return $this->lastKnownTable($exception);
        }

        $this->cache->put(self::CURRENT_CACHE_KEY, $table->toArray(), $this->cacheTtlSeconds);
        $this->cache->forever(self::LAST_KNOWN_CACHE_KEY, $table->toArray());

        return $table;
    }

    private function lastKnownTable(PixelforgeUnavailable $exception): PriceTableData
    {
        $lastKnown = $this->cache->get(self::LAST_KNOWN_CACHE_KEY);

        if (! is_array($lastKnown)) {
            throw PriceTableUnavailable::neverFetched($exception);
        }

        Log::warning('Pixelforge pricing is unavailable; using the last known price table.', [
            'reason' => $exception->getMessage(),
        ]);

        return PriceTableData::fromArray($lastKnown);
    }
}
