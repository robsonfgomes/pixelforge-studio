<?php

declare(strict_types=1);

namespace App\Domain\Pricing\ValueObjects;

use App\Domain\Pricing\Enums\DisplayRounding;
use App\Domain\Pricing\Exceptions\UnsupportedRatePrecision;
use InvalidArgumentException;

final readonly class Money
{
    private const int NANODOLLARS_PER_DOLLAR = 1_000_000_000;

    private const int NANODOLLARS_PER_DISPLAYED_UNIT = 100_000;

    private const int DISPLAYED_UNITS_PER_DOLLAR = 10_000;

    private const int MAX_RATE_DECIMALS = 3;

    public function __construct(public int $nanodollars) {}

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * A rate of $X per 1M tokens is X × 1,000 nanodollars per token, so it is exact only up to 3 decimals.
     */
    public static function fromRatePerMillionTokens(string $rate): self
    {
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $rate, $matches) !== 1) {
            throw new InvalidArgumentException("The rate [{$rate}] is not a decimal string.");
        }

        $decimals = rtrim($matches[2] ?? '', '0');

        if (strlen($decimals) > self::MAX_RATE_DECIMALS) {
            throw UnsupportedRatePrecision::forRate($rate);
        }

        $wholeDollars = (int) $matches[1];
        $thousandths = (int) str_pad($decimals, self::MAX_RATE_DECIMALS, '0');

        return new self($wholeDollars * 1_000 + $thousandths);
    }

    public function toRatePerMillionTokens(): string
    {
        return sprintf('%d.%03d', intdiv($this->nanodollars, 1_000), $this->nanodollars % 1_000);
    }

    public function plus(self $other): self
    {
        return new self($this->nanodollars + $other->nanodollars);
    }

    public function times(int $quantity): self
    {
        return new self($this->nanodollars * $quantity);
    }

    public function withMarkupPercent(int $percent): self
    {
        return new self(self::divideRoundingUp($this->nanodollars * (100 + $percent), 100));
    }

    public function toDecimalString(): string
    {
        $absolute = abs($this->nanodollars);

        return sprintf(
            '%s%d.%09d',
            $this->nanodollars < 0 ? '-' : '',
            intdiv($absolute, self::NANODOLLARS_PER_DOLLAR),
            $absolute % self::NANODOLLARS_PER_DOLLAR,
        );
    }

    public function format(DisplayRounding $rounding): string
    {
        $unit = self::NANODOLLARS_PER_DISPLAYED_UNIT;
        $displayedUnits = match ($rounding) {
            DisplayRounding::Down => self::divideRoundingDown($this->nanodollars, $unit),
            DisplayRounding::Up => self::divideRoundingUp($this->nanodollars, $unit),
            DisplayRounding::HalfUp => self::divideRoundingHalfUp($this->nanodollars, $unit),
        };
        $absolute = abs($displayedUnits);

        return sprintf(
            '%s$%d.%04d',
            $displayedUnits < 0 ? '-' : '',
            intdiv($absolute, self::DISPLAYED_UNITS_PER_DOLLAR),
            $absolute % self::DISPLAYED_UNITS_PER_DOLLAR,
        );
    }

    private static function divideRoundingUp(int $dividend, int $divisor): int
    {
        return intdiv($dividend, $divisor) + ($dividend % $divisor > 0 ? 1 : 0);
    }

    private static function divideRoundingDown(int $dividend, int $divisor): int
    {
        return intdiv($dividend, $divisor) - ($dividend % $divisor < 0 ? 1 : 0);
    }

    private static function divideRoundingHalfUp(int $dividend, int $divisor): int
    {
        $roundedAbsolute = intdiv(abs($dividend) * 2 + $divisor, $divisor * 2);

        return $dividend < 0 ? -$roundedAbsolute : $roundedAbsolute;
    }
}
