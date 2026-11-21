<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use App\Integrations\Pixelforge\PixelforgeApi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\FakePixelforgeApi;
use Tests\TestCase;

class LandingControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function itRendersTheLandingForAGuest(): void
    {
        $this->app->instance(PixelforgeApi::class, FakePixelforgeApi::withDocumentedPrices());

        $response = $this->get(route('home'));

        $response->assertOk()->assertInertia(fn (Assert $page): Assert => $page
            ->component('Landing')
            ->where('auth.user', null)
            ->where('markupPercent', 25),
        );
    }

    #[Test]
    public function itRendersTheLandingForASignedInUser(): void
    {
        $this->app->instance(PixelforgeApi::class, FakePixelforgeApi::withDocumentedPrices());
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('home'));

        $response->assertOk()->assertInertia(fn (Assert $page): Assert => $page
            ->component('Landing')
            ->where('auth.user.id', $user->id),
        );
    }

    #[Test]
    public function itLoadsThePricingExampleAfterThePageFromTheCalculator(): void
    {
        $this->app->instance(PixelforgeApi::class, FakePixelforgeApi::withDocumentedPrices());

        $response = $this->get(route('home'));

        $response->assertInertia(fn (Assert $page): Assert => $page
            ->missing('pricingExample')
            ->loadDeferredProps(fn (Assert $reload): Assert => $reload->where('pricingExample', [
                'available' => true,
                'promptLength' => 200,
                'rows' => [
                    [
                        'quality' => 'Low',
                        'typical' => ['amount' => '0.018218750', 'display' => '$0.0182'],
                        'max' => ['amount' => '0.022175000', 'display' => '$0.0222'],
                    ],
                    [
                        'quality' => 'Medium',
                        'typical' => ['amount' => '0.072500000', 'display' => '$0.0725'],
                        'max' => ['amount' => '0.086612500', 'display' => '$0.0867'],
                    ],
                    [
                        'quality' => 'High',
                        'typical' => ['amount' => '0.285625000', 'display' => '$0.2856'],
                        'max' => ['amount' => '0.340362500', 'display' => '$0.3404'],
                    ],
                ],
                'perReferenceImage' => ['amount' => '0.009562500', 'display' => '$0.0096'],
            ])),
        );
    }

    #[Test]
    public function itResolvesThePricingExampleToUnavailableWithoutAPriceTable(): void
    {
        $pixelforge = FakePixelforgeApi::withDocumentedPrices();
        $pixelforge->makePricingUnavailable();
        $this->app->instance(PixelforgeApi::class, $pixelforge);

        $response = $this->get(route('home'));

        $response->assertOk()->assertInertia(fn (Assert $page): Assert => $page
            ->loadDeferredProps(fn (Assert $reload): Assert => $reload
                ->where('pricingExample', ['available' => false]),
            ),
        );
    }
}
