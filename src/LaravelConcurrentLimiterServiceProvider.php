<?php

declare(strict_types=1);

namespace Largerio\LaravelConcurrentLimiter;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Largerio\LaravelConcurrentLimiter\Commands\ClearCommand;
use Largerio\LaravelConcurrentLimiter\Commands\StatusCommand;
use Largerio\LaravelConcurrentLimiter\Contracts\ConcurrentLimiter;
use Largerio\LaravelConcurrentLimiter\Metrics\MetricsCollector;
use Largerio\LaravelConcurrentLimiter\Metrics\MetricsController;
use Largerio\LaravelConcurrentLimiter\Metrics\MetricsEventSubscriber;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelConcurrentLimiterServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-concurrent-limiter')
            ->hasConfigFile()
            ->hasCommands([
                StatusCommand::class,
                ClearCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(ConcurrentLimiter::class, LaravelConcurrentLimiter::class);
        $this->app->singleton(LaravelConcurrentLimiter::class);
        $this->app->singleton(MetricsCollector::class);
    }

    public function packageBooted(): void
    {
        /** @var \Illuminate\Routing\Router $router */
        $router = $this->app->make('router');
        $router->aliasMiddleware('concurrent.limit', LaravelConcurrentLimiter::class);

        $this->registerMetrics();
    }

    protected function registerMetrics(): void
    {
        /** @var array{enabled: bool, route: string|null, middleware: array<string>} $metricsConfig */
        $metricsConfig = config('concurrent-limiter.metrics', [
            'enabled' => false,
            'route' => null,
            'middleware' => [],
        ]);

        if (! $metricsConfig['enabled']) {
            return;
        }

        // Register event subscriber
        Event::subscribe(MetricsEventSubscriber::class);

        // Register metrics route
        if ($metricsConfig['route'] !== null) {
            Route::get($metricsConfig['route'], MetricsController::class)
                ->middleware($metricsConfig['middleware'])
                ->name('concurrent-limiter.metrics');
        }
    }
}
