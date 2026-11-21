<?php

declare(strict_types=1);

namespace App\Domain\Generations\Enums;

enum ImageQuality: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
