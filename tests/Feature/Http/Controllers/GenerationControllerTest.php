<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use App\Domain\Generations\Models\Generation;
use App\Integrations\Pixelforge\PixelforgeApi;
use App\Jobs\SubmitGenerationJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\FakePixelforgeApi;
use Tests\TestCase;

class GenerationControllerTest extends TestCase
{
    use RefreshDatabase;

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
            ->from(route('dashboard'))
            ->post(route('generations.store'), $this->validPayload([
                'estimated_charge' => 1,
                'estimated_max_charge' => 1,
            ]));

        $response->assertRedirect(route('dashboard'));
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
