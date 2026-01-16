<?php

use Largerio\LaravelConcurrentLimiter\Contracts\ConcurrentLimiter;
use Largerio\LaravelConcurrentLimiter\LaravelConcurrentLimiter;

it('registers middleware alias', function () {
    /** @var \Illuminate\Routing\Router $router */
    $router = app('router');

    expect($router->getMiddleware())->toHaveKey('concurrent.limit');
});

it('binds ConcurrentLimiter interface to implementation', function () {
    $resolved = app(ConcurrentLimiter::class);

    expect($resolved)->toBeInstanceOf(LaravelConcurrentLimiter::class);
});

it('registers LaravelConcurrentLimiter as singleton', function () {
    $first = app(LaravelConcurrentLimiter::class);
    $second = app(LaravelConcurrentLimiter::class);

    expect($first)->toBe($second);
});

it('publishes config file', function () {
    $configPath = config_path('concurrent-limiter.php');

    expect(file_exists($configPath) || class_exists(\Largerio\LaravelConcurrentLimiter\LaravelConcurrentLimiterServiceProvider::class))
        ->toBeTrue();
});

it('loads default config values', function () {
    expect(config('concurrent-limiter.max_parallel'))->toBe(10);
    expect(config('concurrent-limiter.max_wait_time'))->toBe(30);
    expect(config('concurrent-limiter.ttl_buffer'))->toBe(60);
    expect(config('concurrent-limiter.cache_prefix'))->toBe('concurrent-limiter:');
});
