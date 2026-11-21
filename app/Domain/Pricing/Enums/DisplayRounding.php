<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Enums;

enum DisplayRounding
{
    case Down;
    case HalfUp;
    case Up;
}
