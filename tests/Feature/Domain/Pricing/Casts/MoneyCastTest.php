<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Pricing\Casts;

use App\Domain\Pricing\Casts\MoneyCast;
use App\Domain\Pricing\ValueObjects\Money;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MoneyCastTest extends TestCase
{
    #[Test]
    #[TestWith([373_875_000])]
    #[TestWith(['373875000'])]
    public function itReadsTheStoredNanodollarsAsMoney(int|string $storedValue): void
    {
        $model = $this->modelWithAMoneyAttribute();
        $model->setRawAttributes(['amount' => $storedValue]);

        $amount = $model->getAttribute('amount');

        $this->assertInstanceOf(Money::class, $amount);
        $this->assertSame(373_875_000, $amount->nanodollars);
    }

    #[Test]
    public function itStoresMoneyAsNanodollars(): void
    {
        $model = $this->modelWithAMoneyAttribute();

        $model->setAttribute('amount', new Money(305_510_000));

        $this->assertSame(305_510_000, $model->getAttributes()['amount']);
    }

    #[Test]
    public function itKeepsAMissingAmountNull(): void
    {
        $model = $this->modelWithAMoneyAttribute();

        $model->setAttribute('amount', null);

        $this->assertNull($model->getAttributes()['amount']);
        $this->assertNull($model->getAttribute('amount'));
    }

    #[Test]
    public function itRejectsAnAmountThatIsNotMoney(): void
    {
        $model = $this->modelWithAMoneyAttribute();

        $this->expectException(InvalidArgumentException::class);

        $model->setAttribute('amount', 0.37);
    }

    private function modelWithAMoneyAttribute(): Model
    {
        return new class extends Model
        {
            protected function casts(): array
            {
                return ['amount' => MoneyCast::class];
            }
        };
    }
}
