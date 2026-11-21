<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Pixelforge;

use App\Integrations\Pixelforge\Exceptions\PixelforgeUnavailable;
use App\Integrations\Pixelforge\HttpPixelforgeClient;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\FakePixelforgeApi;
use Tests\TestCase;

class HttpPixelforgeClientTest extends TestCase
{
    #[Test]
    public function itReturnsThePriceTableFromThePricingEndpoint(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'pixelforge.test/v1/pricing' => Http::response(FakePixelforgeApi::documentedPricingPayload()),
        ]);

        $table = $this->client()->pricing();

        $this->assertEquals(FakePixelforgeApi::documentedPriceTable(), $table);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://pixelforge.test/v1/pricing');
    }

    #[Test]
    public function itReportsPixelforgeAsUnavailableOnAServerError(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'pixelforge.test/v1/pricing' => Http::response(['error' => ['code' => 'overloaded']], 503),
        ]);

        $this->expectException(PixelforgeUnavailable::class);

        $this->client()->pricing();
    }

    #[Test]
    public function itReportsPixelforgeAsUnavailableWhenTheConnectionFails(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'pixelforge.test/v1/pricing' => Http::failedConnection(),
        ]);

        $this->expectException(PixelforgeUnavailable::class);

        $this->client()->pricing();
    }

    #[Test]
    public function itReportsPixelforgeAsUnavailableWhenTheBodyIsNotJson(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'pixelforge.test/v1/pricing' => Http::response('<html>Bad gateway</html>'),
        ]);

        $this->expectException(PixelforgeUnavailable::class);

        $this->client()->pricing();
    }

    private function client(): HttpPixelforgeClient
    {
        return new HttpPixelforgeClient(
            $this->app->make(HttpFactory::class),
            baseUrl: 'https://pixelforge.test/v1',
            apiKey: 'test-api-key',
            connectTimeoutSeconds: 10,
            timeoutSeconds: 30,
        );
    }
}
