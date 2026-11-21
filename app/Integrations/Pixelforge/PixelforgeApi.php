<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge;

use App\Domain\Pricing\Data\PriceTableData;
use App\Integrations\Pixelforge\Exceptions\PixelforgeUnavailable;

interface PixelforgeApi
{
    /**
     * @throws PixelforgeUnavailable
     */
    public function pricing(): PriceTableData;
}
