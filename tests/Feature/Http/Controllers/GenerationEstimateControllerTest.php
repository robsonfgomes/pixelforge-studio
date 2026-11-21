<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use App\Integrations\Pixelforge\PixelforgeApi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\FakePixelforgeApi;
use Tests\TestCase;

class GenerationEstimateControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function itRejectsAGuestWith401(): void
    {
        $this->app->instance(PixelforgeApi::class, FakePixelforgeApi::withDocumentedPrices());

        $response = $this->postJson(route('generations.estimate'), $this->validPayload());

        $response->assertUnauthorized();
    }

    #[Test]
    public function itReturnsTheMinTypicalAndMaxChargesWithTheirDisplayValues(): void
    {
        $this->app->instance(PixelforgeApi::class, FakePixelforgeApi::withDocumentedPrices());

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('generations.estimate'), $this->validPayload());

        $response->assertOk()->assertExactJson([
            'data' => [
                'min' => ['amount' => '0.357781250', 'display' => '$0.3577'],
                'typical' => ['amount' => '0.412562500', 'display' => '$0.4126'],
                'max' => ['amount' => '0.467343750', 'display' => '$0.4674'],
            ],
        ]);
    }

    #[Test]
    public function itRequiresThePromptTheSizeAndTheQuality(): void
    {
        $this->app->instance(PixelforgeApi::class, FakePixelforgeApi::withDocumentedPrices());

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('generations.estimate'), []);

        $response->assertUnprocessable()->assertInvalid([
            'prompt' => 'The prompt field is required.',
            'size' => 'The size field is required.',
            'quality' => 'The quality field is required.',
        ]);
    }

    #[Test]
    public function itCountsThePromptLengthInUnicodeCharacters(): void
    {
        $this->app->instance(PixelforgeApi::class, FakePixelforgeApi::withDocumentedPrices());
        $user = User::factory()->create();

        $atTheLimit = $this->actingAs($user)
            ->postJson(route('generations.estimate'), $this->validPayload(['prompt' => str_repeat('🌅', 4_000)]));
        $overTheLimit = $this->actingAs($user)
            ->postJson(route('generations.estimate'), $this->validPayload(['prompt' => str_repeat('🌅', 4_001)]));

        $atTheLimit->assertOk();
        $overTheLimit->assertUnprocessable()->assertInvalid([
            'prompt' => 'The prompt field must not be greater than 4000 characters.',
        ]);
    }

    #[Test]
    public function itRejectsAnUnknownSizeAndQuality(): void
    {
        $this->app->instance(PixelforgeApi::class, FakePixelforgeApi::withDocumentedPrices());

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('generations.estimate'), $this->validPayload(['size' => '512x512', 'quality' => 'ultra']));

        $response->assertUnprocessable()->assertInvalid([
            'size' => 'The selected size is invalid.',
            'quality' => 'The selected quality is invalid.',
        ]);
    }

    #[Test]
    public function itRejectsMoreThanFourReferenceImages(): void
    {
        $this->app->instance(PixelforgeApi::class, FakePixelforgeApi::withDocumentedPrices());
        $fiveImages = array_map(fn (int $number): string => "https://example.com/{$number}.png", range(1, 5));

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('generations.estimate'), $this->validPayload(['reference_images' => $fiveImages]));

        $response->assertUnprocessable()->assertInvalid([
            'reference_images' => 'must not have more than 4 items',
        ]);
    }

    #[Test]
    public function itRejectsAReferenceImageThatIsNotAnHttpUrl(): void
    {
        $this->app->instance(PixelforgeApi::class, FakePixelforgeApi::withDocumentedPrices());

        $response = $this->actingAs(User::factory()->create())
            ->postJson(
                route('generations.estimate'),
                $this->validPayload(['reference_images' => ['ftp://example.com/style.png']]),
            );

        $response->assertUnprocessable()->assertInvalid([
            'reference_images.0' => 'must be a valid URL',
        ]);
    }

    #[Test]
    public function itAnswers503WhenNoPriceTableIsAvailable(): void
    {
        $pixelforge = FakePixelforgeApi::withDocumentedPrices();
        $pixelforge->makePricingUnavailable();
        $this->app->instance(PixelforgeApi::class, $pixelforge);

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('generations.estimate'), $this->validPayload());

        $response->assertServiceUnavailable()->assertExactJson(['message' => 'Estimate unavailable']);
    }

    #[Test]
    public function itThrottlesEstimatesPerUser(): void
    {
        config(['generations.throttles.estimates_per_minute' => 2]);
        $this->app->instance(PixelforgeApi::class, FakePixelforgeApi::withDocumentedPrices());
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('generations.estimate'), $this->validPayload())->assertOk();
        $this->actingAs($user)->postJson(route('generations.estimate'), $this->validPayload())->assertOk();
        $third = $this->actingAs($user)->postJson(route('generations.estimate'), $this->validPayload());
        $otherUser = $this->actingAs(User::factory()->create())
            ->postJson(route('generations.estimate'), $this->validPayload());

        $third->assertTooManyRequests();
        $otherUser->assertOk();
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
