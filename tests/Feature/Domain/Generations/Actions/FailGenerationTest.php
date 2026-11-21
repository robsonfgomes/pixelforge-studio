<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Generations\Actions;

use App\Domain\Generations\Actions\FailGeneration;
use App\Domain\Generations\Enums\GenerationStatus;
use App\Domain\Generations\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FailGenerationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function itFailsAGenerationAtNoCost(): void
    {
        $generation = Generation::factory()->create();

        $this->app->make(FailGeneration::class)
            ->handle($generation, 'content_policy_violation', 'The prompt was rejected.');

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('content_policy_violation', $generation->error_code);
        $this->assertSame('The prompt was rejected.', $generation->error_message);
        $this->assertSame(0, $generation->charge?->nanodollars);
        $this->assertSame(0, $generation->provider_cost?->nanodollars);
    }

    #[Test]
    public function itNeverChangesAGenerationThatIsAlreadyFinal(): void
    {
        $generation = Generation::factory()->failed('internal_error', 'Something went wrong on our side.')->create();

        $this->app->make(FailGeneration::class)->handle($generation, 'pixelforge_busy', 'Nothing was created.');

        $generation->refresh();
        $this->assertSame('internal_error', $generation->error_code);
        $this->assertSame('Something went wrong on our side.', $generation->error_message);
    }
}
