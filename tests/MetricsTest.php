<?php

use Illuminate\Support\Facades\Cache;
use Largerio\LaravelConcurrentLimiter\Metrics\MetricsCollector;

beforeEach(function () {
    Cache::flush();
    config()->set('concurrent-limiter.cache_store', null);
});

it('increments requests total counter', function () {
    $collector = new MetricsCollector;

    $collector->incrementRequestsTotal();
    $collector->incrementRequestsTotal();
    $collector->incrementRequestsTotal();

    $metrics = $collector->getMetrics();
    expect($metrics['requests_total']['all'])->toBe(3);
});

it('increments exceeded total counter', function () {
    $collector = new MetricsCollector;

    $collector->incrementExceededTotal();
    $collector->incrementExceededTotal();

    $metrics = $collector->getMetrics();
    expect($metrics['exceeded_total']['all'])->toBe(2);
});

it('increments cache failures counter', function () {
    $collector = new MetricsCollector;

    $collector->incrementCacheFailuresTotal();

    $metrics = $collector->getMetrics();
    expect($metrics['cache_failures_total'])->toBe(1);
});

it('records wait time in histogram buckets', function () {
    $collector = new MetricsCollector;

    $collector->recordWaitTime(0.05); // Should increment 0.05, 0.1, 0.25, 0.5, 1.0, etc.
    $collector->recordWaitTime(0.5);  // Should increment 0.5, 1.0, etc.
    $collector->recordWaitTime(2.0);  // Should increment 2.5, 5.0, etc.

    $metrics = $collector->getMetrics();

    expect($metrics['wait_seconds']['count'])->toBe(3);
    expect($metrics['wait_seconds']['sum'])->toBe(2.55);
});

it('outputs prometheus format', function () {
    $collector = new MetricsCollector;

    $collector->incrementRequestsTotal();
    $collector->incrementExceededTotal();
    $collector->incrementCacheFailuresTotal();
    $collector->recordWaitTime(0.1);

    $output = $collector->toPrometheusFormat();

    expect($output)->toContain('# HELP concurrent_limiter_requests_total');
    expect($output)->toContain('# TYPE concurrent_limiter_requests_total counter');
    expect($output)->toContain('concurrent_limiter_requests_total{key="all"} 1');

    expect($output)->toContain('concurrent_limiter_exceeded_total{key="all"} 1');
    expect($output)->toContain('concurrent_limiter_cache_failures_total 1');

    expect($output)->toContain('# TYPE concurrent_limiter_wait_seconds histogram');
    expect($output)->toContain('concurrent_limiter_wait_seconds_count 1');
});

it('sets active count gauge', function () {
    $collector = new MetricsCollector;

    $collector->setActiveCount('user:123', 5);

    // The active count is stored in cache but not exposed in getMetrics
    // This test just verifies it doesn't throw
    expect(true)->toBeTrue();
});

it('resets metrics', function () {
    $collector = new MetricsCollector;

    $collector->incrementRequestsTotal();
    $collector->incrementExceededTotal();

    $collector->reset();

    $metrics = $collector->getMetrics();
    expect($metrics['requests_total']['all'])->toBe(0);
    expect($metrics['exceeded_total']['all'])->toBe(0);
});

it('has metrics config option', function () {
    expect(config('concurrent-limiter.metrics.enabled'))->toBeFalse();
    expect(config('concurrent-limiter.metrics.route'))->toBe('/concurrent-limiter/metrics');
    expect(config('concurrent-limiter.metrics.middleware'))->toBe([]);
});
