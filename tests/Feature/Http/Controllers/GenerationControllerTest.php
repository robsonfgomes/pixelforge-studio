<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use App\Domain\Generations\Models\Generation;
use App\Integrations\Pixelforge\PixelforgeApi;
use App\Jobs\SubmitGenerationJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\FakePixelforgeApi;
use Tests\TestCase;

class GenerationControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function itRedirectsAGuestAwayFromThePage(): void
    {
        $response = $this->get(route('generations.index'));

        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function itShowsTheUsersFiftyLatestGenerationsNewestFirst(): void
    {
        $this->freezeSecond();
        $user = User::factory()->create();
        Generation::factory()->for($user)->count(51)->sequence(
            fn (Sequence $sequence): array => ['created_at' => now()->subMinutes($sequence->index)],
        )->create();
        $newest = Generation::query()->where('user_id', $user->id)->orderByDesc('created_at')->firstOrFail();
        Generation::factory()->create(['created_at' => now()->addMinute()]);

        $response = $this->actingAs($user)->get(route('generations.index'));

        $response->assertOk()->assertInertia(fn (Assert $page): Assert => $page
            ->component('generations/Index')
            ->has('generations', 50)
            ->where('generations.0.id', $newest->id),
        );
    }

    #[Test]
    public function itReportsProgressOnlyForGenerationsWithinThePollingWindow(): void
    {
        $user = User::factory()->create();
        Generation::factory()->for($user)->succeeded()->create();
        Generation::factory()->for($user)->processing()->create(['created_at' => now()->subMinutes(121)]);

        $stale = $this->actingAs($user)->get(route('generations.index'));
        Generation::factory()->for($user)->processing()->create();
        $recent = $this->actingAs($user)->get(route('generations.index'));

        $stale->assertInertia(fn (Assert $page): Assert => $page->where('hasInProgress', false));
        $recent->assertInertia(fn (Assert $page): Assert => $page->where('hasInProgress', true));
    }

    #[Test]
    public function itSendsTheComposerOptionsAndLimits(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('generations.index'));

        $response->assertInertia(fn (Assert $page): Assert => $page->where('composer', [
            'sizes' => [
                ['value' => '1024x1024', 'aspectRatio' => '1:1'],
                ['value' => '1536x1024', 'aspectRatio' => '3:2'],
                ['value' => '1024x1536', 'aspectRatio' => '2:3'],
            ],
            'qualities' => [
                ['value' => 'low', 'label' => 'Low'],
                ['value' => 'medium', 'label' => 'Medium'],
                ['value' => 'high', 'label' => 'High'],
            ],
            'defaults' => ['size' => '1024x1024', 'quality' => 'medium'],
            'limits' => ['promptLength' => 4000, 'referenceImages' => 4],
        ]));
    }

    #[Test]
    public function itReloadsOnlyTheHistoryAndProgressWhenAsked(): void
    {
        $user = User::factory()->create();
        Generation::factory()->for($user)->processing()->create();

        $response = $this->actingAs($user)->get(route('generations.index'));

        $response->assertInertia(fn (Assert $page): Assert => $page
            ->has('composer')
            ->reloadOnly(['generations', 'hasInProgress'], fn (Assert $reload): Assert => $reload
                ->has('generations', 1)
                ->where('hasInProgress', true)
                ->missing('composer'),
            ),
        );
    }

    #[Test]
    public function itRedirectsAGuestToTheLoginPage(): void
    {
        $this->app->instance(PixelforgeApi::class, FakePixelforgeApi::withDocumentedPrices());

        $response = $this->post(route('generations.store'), $this->validPayload());

        $response->assertRedirect(route('login'));
        $this->assertDatabaseCount('generations', 0);
    }

    #[Test]
    public function itStoresTheGenerationWithTheServerEstimateAndQueuesItsSubmission(): void
    {
        Queue::fake([SubmitGenerationJob::class]);
        $this->app->instance(PixelforgeApi::class, FakePixelforgeApi::withDocumentedPrices());
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post(route('generations.store'), $this->validPayload([
                'estimated_charge' => 1,
                'estimated_max_charge' => 1,
            ]));

        $response->assertRedirect(route('generations.index'));
        $generation = Generation::query()->sole();
        $this->assertTrue($generation->user->is($user));
        $this->assertSame(412_562_500, $generation->estimated_charge->nanodollars);
        $this->assertSame(467_343_750, $generation->estimated_max_charge->nanodollars);
        Queue::assertPushed(
            SubmitGenerationJob::class,
            fn (SubmitGenerationJob $job): bool => $job->generationId === $generation->id,
        );
    }

    #[Test]
    public function itValidatesTheRequestLikeTheEstimate(): void
    {
        Queue::fake([SubmitGenerationJob::class]);
        $this->app->instance(PixelforgeApi::class, FakePixelforgeApi::withDocumentedPrices());

        $response = $this->actingAs(User::factory()->create())
            ->post(route('generations.store'), $this->validPayload(['prompt' => '']));

        $response->assertInvalid(['prompt' => 'The prompt field is required.']);
        $this->assertDatabaseCount('generations', 0);
        Queue::assertNotPushed(SubmitGenerationJob::class);
    }

    #[Test]
    public function itShowsAnEstimateErrorWhenNoPriceTableIsAvailable(): void
    {
        Queue::fake([SubmitGenerationJob::class]);
        $pixelforge = FakePixelforgeApi::withDocumentedPrices();
        $pixelforge->makePricingUnavailable();
        $this->app->instance(PixelforgeApi::class, $pixelforge);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('generations.store'), $this->validPayload());

        $response->assertInvalid(['estimate' => 'Estimate unavailable. Please try again in a moment.']);
        $this->assertDatabaseCount('generations', 0);
        Queue::assertNotPushed(SubmitGenerationJob::class);
    }

    #[Test]
    public function itThrottlesGenerationRequestsPerUser(): void
    {
        config(['generations.throttles.requests_per_minute' => 1]);
        Queue::fake([SubmitGenerationJob::class]);
        $this->app->instance(PixelforgeApi::class, FakePixelforgeApi::withDocumentedPrices());
        $user = User::factory()->create();

        $first = $this->actingAs($user)->post(route('generations.store'), $this->validPayload());
        $second = $this->actingAs($user)->post(route('generations.store'), $this->validPayload());

        $first->assertRedirect();
        $second->assertTooManyRequests();
        $this->assertDatabaseCount('generations', 1);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return [
            'prompt' => str_repeat('a', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style-1.png', 'https://example.com/style-2.png'],
            ...$overrides,
        ];
    }
}
