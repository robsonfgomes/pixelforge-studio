<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Pricing\Actions\GetPriceTable;
use App\Domain\Pricing\PricingCalculator;
use App\Integrations\Pixelforge\HttpPixelforgeClient;
use App\Integrations\Pixelforge\PixelforgeApi;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PixelforgeApi::class, fn (Application $app): PixelforgeApi => new HttpPixelforgeClient(
            $app->make(HttpFactory::class),
            config('services.pixelforge.base_url'),
            config('services.pixelforge.api_key'),
            config('services.pixelforge.connect_timeout'),
            config('services.pixelforge.timeout'),
        ));

        $this->app->bind(PricingCalculator::class, fn (): PricingCalculator => new PricingCalculator(
            config('generations.markup_percent'),
        ));

        $this->app->when(GetPriceTable::class)
            ->needs('$cacheTtlSeconds')
            ->giveConfig('services.pixelforge.pricing_cache_ttl');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('generation-estimates', fn (Request $request): Limit => Limit::perMinute(
            config('generations.throttles.estimates_per_minute'),
        )->by('user:'.$request->user()?->getAuthIdentifier()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
