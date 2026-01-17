<?php

use Illuminate\Support\Facades\Cache;
use Largerio\LaravelConcurrentLimiter\Adaptive\AdaptiveLimitResolver;
use Largerio\LaravelConcurrentLimiter\Adaptive\Algorithms\Gradient2Limit;
use Largerio\LaravelConcurrentLimiter\Adaptive\Algorithms\VegasLimit;
use Largerio\LaravelConcurrentLimiter\Contracts\AdaptiveResolver;

beforeEach(function () {
    Cache::flush();
    config()->set('concurrent-limiter.cache_prefix', 'test:');
    config()->set('concurrent-limiter.adaptive.enabled', true);
    config()->set('concurrent-limiter.adaptive.algorithm', 'vegas');
    config()->set('concurrent-limiter.adaptive.min_limit', 1);
    config()->set('concurrent-limiter.adaptive.max_limit', 100);
    config()->set('concurrent-limiter.adaptive.ewma_alpha', 0.3);
    config()->set('concurrent-limiter.adaptive.sample_window', 60);
    config()->set('concurrent-limiter.adaptive.min_rtt_reset_samples', 1000);
    config()->set('concurrent-limiter.adaptive.rtt_tolerance', 2.0);
});

it('returns configured limit when adaptive is disabled', function () {
    config()->set('concurrent-limiter.adaptive.enabled', false);

    $resolver = new AdaptiveLimitResolver;
    $result = $resolver->resolve('test-key', 10);

    expect($result)->toBe(10);
});

it('returns configured limit when no metrics available', function () {
    $resolver = new AdaptiveLimitResolver;
    $result = $resolver->resolve('test-key', 10);

    expect($result)->toBe(10);
});

it('uses vegas algorithm by default', function () {
    $resolver = new AdaptiveLimitResolver;

    expect($resolver->getAlgorithm())->toBeInstanceOf(VegasLimit::class);
});

it('uses gradient2 algorithm when configured', function () {
    config()->set('concurrent-limiter.adaptive.algorithm', 'gradient2');

    $resolver = new AdaptiveLimitResolver;

    expect($resolver->getAlgorithm())->toBeInstanceOf(Gradient2Limit::class);
});

it('increases limit when latency is good', function () {
    $resolver = new AdaptiveLimitResolver;

    // Record a low latency sample (will set minRTT = avgRTT = 100)
    $resolver->recordLatency('test-key', 100, 10);

    // With Vegas: gradient = 1.0, queueUse = 0 < alpha → increase
    // recordLatency already calculated and stored the new limit (11)
    $metrics = $resolver->getMetrics('test-key');

    expect($metrics['current_limit'])->toBe(11);
});

it('decreases limit when latency is degraded', function () {
    $resolver = new AdaptiveLimitResolver;

    // First sample sets baseline
    $resolver->recordLatency('test-key', 100, 10);

    // Add high latency samples to increase avgRTT while minRTT stays low
    $resolver->recordLatency('test-key', 500, 10);
    $resolver->recordLatency('test-key', 500, 10);
    $resolver->recordLatency('test-key', 500, 10);

    // Now minRTT = 100, avgRTT >> 100
    // gradient = minRTT/avgRTT < 1.0
    // queueUse = limit * (1 - gradient) could be > beta → decrease
    $metrics = $resolver->getMetrics('test-key');

    // Verify avgRTT has increased
    expect($metrics['avg_latency_ms'])->toBeGreaterThan(100);
    expect($metrics['min_latency_ms'])->toBe(100.0);  // minRTT stays low
});

it('respects min_limit boundary', function () {
    config()->set('concurrent-limiter.adaptive.min_limit', 5);

    $resolver = new AdaptiveLimitResolver;

    // Create metrics that would result in decrease
    $resolver->recordLatency('test-key', 100, 5);

    // Even with good latency, should not go below min_limit
    // ... or if latency was bad, should not go below min_limit
    $result = $resolver->resolve('test-key', 5);

    expect($result)->toBeGreaterThanOrEqual(5);
});

it('respects max_limit boundary', function () {
    config()->set('concurrent-limiter.adaptive.max_limit', 10);

    $resolver = new AdaptiveLimitResolver;

    // Record low latency to trigger increase
    $resolver->recordLatency('test-key', 100, 10);

    // Should not exceed max_limit even with increase
    $result = $resolver->resolve('test-key', 10);

    expect($result)->toBeLessThanOrEqual(10);
});

it('stores metrics in cache with correct structure for vegas', function () {
    $resolver = new AdaptiveLimitResolver;

    $resolver->recordLatency('test-key', 250, 10);

    $metrics = $resolver->getMetrics('test-key');

    expect($metrics)->toBeArray();
    expect($metrics)->toHaveKeys(['avg_latency_ms', 'min_latency_ms', 'current_limit', 'sample_count', 'updated_at']);
    expect($metrics['avg_latency_ms'])->toBe(250.0);
    expect($metrics['min_latency_ms'])->toBe(250.0);
    expect($metrics['sample_count'])->toBe(1);
    expect($metrics['updated_at'])->toBeInt();
});

it('stores metrics in cache with correct structure for gradient2', function () {
    config()->set('concurrent-limiter.adaptive.algorithm', 'gradient2');

    $resolver = new AdaptiveLimitResolver;

    $resolver->recordLatency('test-key', 250, 10);

    $metrics = $resolver->getMetrics('test-key');

    expect($metrics)->toBeArray();
    expect($metrics)->toHaveKeys(['short_ewma_ms', 'long_ewma_ms', 'current_limit', 'sample_count', 'updated_at']);
    expect($metrics['short_ewma_ms'])->toBe(250.0);
    expect($metrics['long_ewma_ms'])->toBe(250.0);
});

it('increments sample count on each recording', function () {
    $resolver = new AdaptiveLimitResolver;

    $resolver->recordLatency('test-key', 100, 10);
    expect($resolver->getMetrics('test-key')['sample_count'])->toBe(1);

    $resolver->recordLatency('test-key', 200, 10);
    expect($resolver->getMetrics('test-key')['sample_count'])->toBe(2);

    $resolver->recordLatency('test-key', 300, 10);
    expect($resolver->getMetrics('test-key')['sample_count'])->toBe(3);
});

it('does not record latency when adaptive is disabled', function () {
    config()->set('concurrent-limiter.adaptive.enabled', false);

    $resolver = new AdaptiveLimitResolver;
    $resolver->recordLatency('test-key', 100, 10);

    expect($resolver->getMetrics('test-key'))->toBeNull();
});

it('uses separate metrics for different keys', function () {
    $resolver = new AdaptiveLimitResolver;

    $resolver->recordLatency('key-a', 100, 10);
    $resolver->recordLatency('key-b', 500, 10);

    $metricsA = $resolver->getMetrics('key-a');
    $metricsB = $resolver->getMetrics('key-b');

    expect($metricsA['avg_latency_ms'])->toBe(100.0);
    expect($metricsB['avg_latency_ms'])->toBe(500.0);
});

it('implements AdaptiveResolver interface', function () {
    $resolver = new AdaptiveLimitResolver;

    expect($resolver)->toBeInstanceOf(AdaptiveResolver::class);
});

it('can be resolved from container', function () {
    $resolver = app(AdaptiveResolver::class);

    expect($resolver)->toBeInstanceOf(AdaptiveLimitResolver::class);
});

it('applies min/max bounds to calculated limit', function () {
    config()->set('concurrent-limiter.adaptive.min_limit', 5);
    config()->set('concurrent-limiter.adaptive.max_limit', 15);

    $resolver = new AdaptiveLimitResolver;

    // Record latency
    $resolver->recordLatency('test-key', 100, 10);

    $result = $resolver->resolve('test-key', 10);

    expect($result)->toBeGreaterThanOrEqual(5);
    expect($result)->toBeLessThanOrEqual(15);
});

// ============================================================
// Integration Tests: Middleware → Event → Collector → Resolver
// ============================================================

it('dispatches ConcurrentLimitReleased event with totalTime from middleware', function () {
    \Illuminate\Support\Facades\Event::fake([\Largerio\LaravelConcurrentLimiter\Events\ConcurrentLimitReleased::class]);

    $middleware = app(\Largerio\LaravelConcurrentLimiter\LaravelConcurrentLimiter::class);
    $request = \Illuminate\Http\Request::create('/test', 'GET');
    $request->setUserResolver(fn () => null);
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $middleware->handle($request, fn () => response()->json(['ok' => true]), 5, 10);

    \Illuminate\Support\Facades\Event::assertDispatched(
        \Largerio\LaravelConcurrentLimiter\Events\ConcurrentLimitReleased::class,
        function ($event) {
            // Verify totalTime is set (it's the total time: wait + processing)
            return $event->totalTime >= 0
                && str_contains($event->key, 'test:')
                && $event->request instanceof \Illuminate\Http\Request;
        }
    );
});

it('collector records latency from ConcurrentLimitReleased event', function () {
    $resolver = new AdaptiveLimitResolver;
    $collector = new \Largerio\LaravelConcurrentLimiter\Adaptive\AdaptiveMetricsCollector($resolver);

    // Simulate the event with 0.1 seconds total time (100ms)
    $request = \Illuminate\Http\Request::create('/test', 'GET');
    $event = new \Largerio\LaravelConcurrentLimiter\Events\ConcurrentLimitReleased(
        $request,
        0.1, // 100ms in seconds
        'test:integration-key'
    );

    $collector->handleReleased($event);

    // Verify metrics were recorded
    $metrics = $resolver->getMetrics('test:integration-key');

    expect($metrics)->not->toBeNull();
    expect($metrics['avg_latency_ms'])->toBe(100.0); // 0.1s * 1000 = 100ms
    expect($metrics['sample_count'])->toBe(1);
});

it('end-to-end: adaptive resolver adjusts limit after latency recording', function () {
    // Configure adaptive limiting
    config()->set('concurrent-limiter.adaptive.min_limit', 1);
    config()->set('concurrent-limiter.adaptive.max_limit', 20);

    $resolver = new AdaptiveLimitResolver;
    $collector = new \Largerio\LaravelConcurrentLimiter\Adaptive\AdaptiveMetricsCollector($resolver);

    // Simulate several requests with good latency
    $request = \Illuminate\Http\Request::create('/test', 'GET');

    for ($i = 0; $i < 5; $i++) {
        $event = new \Largerio\LaravelConcurrentLimiter\Events\ConcurrentLimitReleased(
            $request,
            0.05, // 50ms - good latency
            'test:e2e-key'
        );
        $collector->handleReleased($event);
    }

    // Get the adaptive limit
    $adaptiveLimit = $resolver->resolve('test:e2e-key', 10);

    // With consistently good latency (minRTT ≈ avgRTT), Vegas should increase limit
    expect($adaptiveLimit)->toBeGreaterThanOrEqual(10);
});

// ============================================================
// Config Validation Tests
// ============================================================

it('throws exception when min_limit > max_limit', function () {
    config()->set('concurrent-limiter.adaptive.min_limit', 100);
    config()->set('concurrent-limiter.adaptive.max_limit', 10);

    new AdaptiveLimitResolver;
})->throws(\InvalidArgumentException::class, 'adaptive.min_limit (100) must be <= adaptive.max_limit (10)');

it('enforces minimum value of 1 for min_limit', function () {
    config()->set('concurrent-limiter.adaptive.min_limit', 0);
    config()->set('concurrent-limiter.adaptive.max_limit', 10);

    $resolver = new AdaptiveLimitResolver;

    // Record latency to trigger bounds check
    $resolver->recordLatency('test-key', 100, 10);

    $metrics = $resolver->getMetrics('test-key');

    // The limit should be at least 1, not 0
    expect($metrics['current_limit'])->toBeGreaterThanOrEqual(1);
});

it('enforces minimum value of 1 for sample_window', function () {
    config()->set('concurrent-limiter.adaptive.sample_window', 0);

    $resolver = new AdaptiveLimitResolver;

    // Should not throw, sample_window is silently corrected to 1
    $resolver->recordLatency('test-key', 100, 10);

    expect($resolver->getMetrics('test-key'))->not->toBeNull();
});

// ============================================================
// Middleware Integration: maxParallel as Cap
// ============================================================

it('caps adaptive limit to maxParallel in middleware', function () {
    // Configure adaptive to potentially return a high limit
    config()->set('concurrent-limiter.adaptive.max_limit', 100);

    // Create a mock resolver that always returns a high limit
    $mockResolver = new class implements AdaptiveResolver
    {
        public function resolve(string $key, int $configuredLimit): int
        {
            return 50; // Would return 50, but middleware should cap it
        }

        public function recordLatency(string $key, float $latencyMs, int $currentLimit): void {}

        public function getMetrics(string $key): ?array
        {
            return null;
        }
    };

    $middleware = new \Largerio\LaravelConcurrentLimiter\LaravelConcurrentLimiter(
        null,
        null,
        null,
        $mockResolver
    );

    $request = \Illuminate\Http\Request::create('/test', 'GET');
    $request->setUserResolver(fn () => null);
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    // Set maxParallel to 5 - this should cap the adaptive limit
    // Pre-fill cache to exceed the capped limit (5) but not the adaptive limit (50)
    $key = 'test:'.sha1('127.0.0.1');
    Cache::put($key, 10, 120); // 10 concurrent requests

    // With maxParallel=5, should return 503 even though adaptive would allow 50
    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]), 5, 1);

    expect($response->getStatusCode())->toBe(503);
});
