<?php

declare(strict_types=1);

namespace Largerio\LaravelConcurrentLimiter;

use Largerio\LaravelConcurrentLimiter\Contracts\ConcurrentLimiter;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelConcurrentLimiterServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-concurrent-limiter')
            ->hasConfigFile();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(ConcurrentLimiter::class, LaravelConcurrentLimiter::class);
        $this->app->singleton(LaravelConcurrentLimiter::class);
    }

    public function packageBooted(): void
    {
        /** @var \Illuminate\Routing\Router $router */
        $router = $this->app->make('router');
        $router->aliasMiddleware('concurrent.limit', LaravelConcurrentLimiter::class);
    }
}
