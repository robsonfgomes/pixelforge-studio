<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Pricing\ValueObjects;

use App\Domain\Pricing\Enums\DisplayRounding;
use App\Domain\Pricing\Exceptions\UnsupportedRatePrecision;
use App\Domain\Pricing\ValueObjects\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    #[Test]
    #[DataProvider('ratesPerMillionTokens')]
    public function itConvertsARatePerMillionTokensToNanodollarsPerToken(string $rate, int $nanodollarsPerToken): void
    {
        $money = Money::fromRatePerMillionTokens($rate);

        $this->assertSame($nanodollarsPerToken, $money->nanodollars);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function ratesPerMillionTokens(): array
    {
        return [
            'two decimals' => ['12.50', 12_500],
            'whole dollars' => ['40', 40_000],
            'three decimals' => ['0.001', 1],
            'trailing zeros past three decimals' => ['5.000000', 5_000],
        ];
    }

    #[Test]
    public function itRejectsARateThatCannotBeExactInNanodollars(): void
    {
        $this->expectException(UnsupportedRatePrecision::class);

        Money::fromRatePerMillionTokens('12.5005');
    }

    #[Test]
    #[TestWith([''])]
    #[TestWith(['-1.00'])]
    #[TestWith(['1,50'])]
    #[TestWith(['abc'])]
    public function itRejectsAMalformedRate(string $rate): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromRatePerMillionTokens($rate);
    }

    #[Test]
    #[TestWith([373_875_000, '0.373875000'])]
    #[TestWith([1, '0.000000001'])]
    #[TestWith([0, '0.000000000'])]
    #[TestWith([1_234_567_890_123, '1234.567890123'])]
    #[TestWith([-5, '-0.000000005'])]
    public function itWritesTheExactAmountWithNineDecimals(int $nanodollars, string $exactAmount): void
    {
        $money = new Money($nanodollars);

        $this->assertSame($exactAmount, $money->toDecimalString());
    }

    #[Test]
    #[TestWith([373_875_000, 467_343_750])]
    #[TestWith([4, 5])]
    #[TestWith([1, 2])]
    #[TestWith([0, 0])]
    #[TestWith([-1, -1])]
    public function itAppliesTheMarkupRoundingUpTowardsPositiveInfinity(int $nanodollars, int $withMarkup): void
    {
        $money = new Money($nanodollars);

        $this->assertSame($withMarkup, $money->withMarkupPercent(25)->nanodollars);
    }

    #[Test]
    #[DataProvider('displayValues')]
    public function itShowsFourDecimalsWithTheRequestedRounding(
        int $nanodollars,
        DisplayRounding $rounding,
        string $displayValue,
    ): void {
        $money = new Money($nanodollars);

        $this->assertSame($displayValue, $money->format($rounding));
    }

    /**
     * @return array<string, array{int, DisplayRounding, string}>
     */
    public static function displayValues(): array
    {
        return [
            'down drops the remainder' => [447_600_001, DisplayRounding::Down, '$0.4476'],
            'up keeps any remainder' => [447_600_001, DisplayRounding::Up, '$0.4477'],
            'up leaves an exact value alone' => [447_600_000, DisplayRounding::Up, '$0.4476'],
            'half-up below the half' => [392_849_999, DisplayRounding::HalfUp, '$0.3928'],
            'half-up at the half' => [392_850_000, DisplayRounding::HalfUp, '$0.3929'],
            'whole dollars' => [1_234_500_000_000, DisplayRounding::HalfUp, '$1234.5000'],
            'negative up goes towards zero' => [-100_001, DisplayRounding::Up, '-$0.0001'],
            'negative down goes away from zero' => [-100_001, DisplayRounding::Down, '-$0.0002'],
        ];
    }

    #[Test]
    public function itAddsAndMultipliesInNanodollars(): void
    {
        $ratePerToken = new Money(12_500);

        $total = $ratePerToken->times(8_000)->plus(new Money(1));

        $this->assertSame(100_000_001, $total->nanodollars);
    }
}
