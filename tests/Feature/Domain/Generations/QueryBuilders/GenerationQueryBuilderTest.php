<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Generations\QueryBuilders;

use App\Domain\Generations\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerationQueryBuilderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function itFindsOnlyTheGenerationsOfTheGivenUser(): void
    {
        $user = User::factory()->create();
        $own = Generation::factory()->for($user)->create();
        Generation::factory()->create();

        $ids = Generation::query()->forUser($user)->pluck('id')->all();

        $this->assertSame([$own->id], $ids);
    }

    #[Test]
    public function itTreatsEveryGenerationThatIsNotFinalAsInProgress(): void
    {
        $queued = Generation::factory()->create();
        $staleProcessing = Generation::factory()->processing()->create(['created_at' => now()->subDay()]);
        Generation::factory()->succeeded()->create();
        Generation::factory()->failed('internal_error', 'Something went wrong.')->create();

        $ids = Generation::query()->inProgress()->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$queued->id, $staleProcessing->id])->sort()->values()->all(), $ids);
    }

    #[Test]
    public function itTreatsOnlyGenerationsInProgressCreatedSinceTheCutoffAsRecentlyActive(): void
    {
        $this->freezeSecond();
        $recent = Generation::factory()->processing()->create(['created_at' => now()->subMinutes(119)]);
        Generation::factory()->processing()->create(['created_at' => now()->subMinutes(121)]);
        Generation::factory()->succeeded()->create();

        $ids = Generation::query()->recentlyActive(now()->subMinutes(120))->pluck('id')->all();

        $this->assertSame([$recent->id], $ids);
    }
}
