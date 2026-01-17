<?php

use Largerio\LaravelConcurrentLimiter\Adaptive\Algorithms\Gradient2Limit;

beforeEach(function () {
    config()->set('concurrent-limiter.adaptive.rtt_tolerance', 2.0);
});

it('initializes metrics correctly', function () {
    $gradient2 = new Gradient2Limit;

    $metrics = $gradient2->getInitialMetrics(100.0, 10);

    expect($metrics)->toMatchArray([
        'short_ewma_ms' => 100.0,
        'long_ewma_ms' => 100.0,
        'current_limit' => 10,
        'sample_count' => 1,
    ]);
    expect($metrics)->toHaveKey('updated_at');
});

it('increases limit when gradient clearly above 1.0', function () {
    $gradient2 = new Gradient2Limit;

    // gradient = long_ewma / short_ewma = 105 / 100 = 1.05 > 1.02 (stability threshold) → increase
    // With hysteresis, gradient must be >= 1.02 to trigger increase
    $metrics = [
        'short_ewma_ms' => 100.0,
        'long_ewma_ms' => 105.0,  // gradient = 1.05
        'current_limit' => 10,
        'sample_count' => 1,
        'updated_at' => time(),
    ];

    $newLimit = $gradient2->calculate($metrics, 10);

    expect($newLimit)->toBe(11);
});

it('maintains limit when gradient is exactly 1.0 (stability zone)', function () {
    $gradient2 = new Gradient2Limit;

    // gradient = long_ewma / short_ewma = 100 / 100 = 1.0
    // With hysteresis threshold of 0.02, gradient of 1.0 is in stability zone
    $metrics = [
        'short_ewma_ms' => 100.0,
        'long_ewma_ms' => 100.0,  // gradient = 1.0 exactly
        'current_limit' => 10,
        'sample_count' => 1,
        'updated_at' => time(),
    ];

    $newLimit = $gradient2->calculate($metrics, 10);

    expect($newLimit)->toBe(10);  // Stable - no change
});

it('increases limit when latency is improving', function () {
    $gradient2 = new Gradient2Limit;

    // gradient = long_ewma / short_ewma = 150 / 100 = 1.5 → increase
    // This means long-term latency is higher than short-term (improving)
    $metrics = [
        'short_ewma_ms' => 100.0,
        'long_ewma_ms' => 150.0,  // gradient = 1.5 > 1.0
        'current_limit' => 10,
        'sample_count' => 1,
        'updated_at' => time(),
    ];

    $newLimit = $gradient2->calculate($metrics, 10);

    expect($newLimit)->toBe(11);
});

it('decreases limit when gradient is below tolerance threshold', function () {
    $gradient2 = new Gradient2Limit;

    // tolerance = 2.0, threshold = 1/2.0 = 0.5
    // gradient = long_ewma / short_ewma = 40 / 100 = 0.4 < 0.5 → decrease
    $metrics = [
        'short_ewma_ms' => 100.0,
        'long_ewma_ms' => 40.0,  // gradient = 0.4 < threshold (0.5)
        'current_limit' => 10,
        'sample_count' => 1,
        'updated_at' => time(),
    ];

    $newLimit = $gradient2->calculate($metrics, 10);

    expect($newLimit)->toBe(9);
});

it('maintains limit within tolerance range', function () {
    $gradient2 = new Gradient2Limit;

    // tolerance = 2.0, threshold = 0.5
    // gradient = long_ewma / short_ewma = 60 / 100 = 0.6
    // 0.5 <= 0.6 < 1.0 → maintain (within tolerance)
    $metrics = [
        'short_ewma_ms' => 100.0,
        'long_ewma_ms' => 60.0,  // gradient = 0.6, within tolerance
        'current_limit' => 10,
        'sample_count' => 1,
        'updated_at' => time(),
    ];

    $newLimit = $gradient2->calculate($metrics, 10);

    expect($newLimit)->toBe(10);  // Stable
});

it('respects rtt_tolerance config', function () {
    // Lower tolerance = stricter threshold
    config()->set('concurrent-limiter.adaptive.rtt_tolerance', 1.5);

    $gradient2 = new Gradient2Limit;

    // threshold = 1/1.5 = 0.667
    // gradient = 60/100 = 0.6 < 0.667 → decrease
    $metrics = [
        'short_ewma_ms' => 100.0,
        'long_ewma_ms' => 60.0,  // gradient = 0.6 < threshold (0.667)
        'current_limit' => 10,
        'sample_count' => 1,
        'updated_at' => time(),
    ];

    $newLimit = $gradient2->calculate($metrics, 10);

    expect($newLimit)->toBe(9);  // Now decreases due to stricter tolerance
});

it('uses short EWMA alpha of 0.5', function () {
    $gradient2 = new Gradient2Limit;

    $metrics = $gradient2->getInitialMetrics(100.0, 10);

    // Short EWMA: 0.5 * 200 + 0.5 * 100 = 150
    $metrics = $gradient2->updateMetrics($metrics, 200.0);

    expect($metrics['short_ewma_ms'])->toBe(150.0);
});

it('uses long EWMA alpha of 0.1', function () {
    $gradient2 = new Gradient2Limit;

    $metrics = $gradient2->getInitialMetrics(100.0, 10);

    // Long EWMA: 0.1 * 200 + 0.9 * 100 = 110
    $metrics = $gradient2->updateMetrics($metrics, 200.0);

    expect($metrics['long_ewma_ms'])->toBe(110.0);
});

it('short EWMA is more responsive than long EWMA', function () {
    $gradient2 = new Gradient2Limit;

    $metrics = $gradient2->getInitialMetrics(100.0, 10);

    // Add several high latency samples
    $metrics = $gradient2->updateMetrics($metrics, 500.0);
    $metrics = $gradient2->updateMetrics($metrics, 500.0);
    $metrics = $gradient2->updateMetrics($metrics, 500.0);

    // Short EWMA should have moved more than long EWMA
    expect($metrics['short_ewma_ms'])->toBeGreaterThan($metrics['long_ewma_ms']);
});

it('increments sample count', function () {
    $gradient2 = new Gradient2Limit;

    $metrics = $gradient2->getInitialMetrics(100.0, 10);
    expect($metrics['sample_count'])->toBe(1);

    $metrics = $gradient2->updateMetrics($metrics, 200.0);
    expect($metrics['sample_count'])->toBe(2);

    $metrics = $gradient2->updateMetrics($metrics, 300.0);
    expect($metrics['sample_count'])->toBe(3);
});

it('handles zero short EWMA gracefully', function () {
    $gradient2 = new Gradient2Limit;

    $metrics = [
        'short_ewma_ms' => 0.0,
        'long_ewma_ms' => 100.0,
        'current_limit' => 10,
        'sample_count' => 1,
        'updated_at' => time(),
    ];

    $newLimit = $gradient2->calculate($metrics, 10);

    expect($newLimit)->toBe(10);  // Returns current limit, no division by zero
});

it('handles zero long EWMA gracefully', function () {
    $gradient2 = new Gradient2Limit;

    // When long_ewma_ms is 0 but short is positive, algorithm should return current limit
    // to avoid incorrect gradient calculation (gradient = 0 would always trigger decrease)
    $metrics = [
        'short_ewma_ms' => 100.0,
        'long_ewma_ms' => 0.0,  // Edge case: zero long EWMA
        'current_limit' => 10,
        'sample_count' => 1,
        'updated_at' => time(),
    ];

    $newLimit = $gradient2->calculate($metrics, 10);

    expect($newLimit)->toBe(10);  // Returns current limit, avoids incorrect behavior
});

it('never decreases limit below 1', function () {
    $gradient2 = new Gradient2Limit;

    // Extreme case: limit = 1, should decrease but floor at 1
    $metrics = [
        'short_ewma_ms' => 100.0,
        'long_ewma_ms' => 10.0,  // gradient = 0.1 < threshold
        'current_limit' => 1,
        'sample_count' => 1,
        'updated_at' => time(),
    ];

    $newLimit = $gradient2->calculate($metrics, 1);

    expect($newLimit)->toBe(1);  // max(1, 1-1) = 1
});

it('detects latency trend through EWMA divergence', function () {
    $gradient2 = new Gradient2Limit;

    // Start with stable latency
    $metrics = $gradient2->getInitialMetrics(100.0, 10);

    // Simulate sudden latency spike
    for ($i = 0; $i < 5; $i++) {
        $metrics = $gradient2->updateMetrics($metrics, 300.0);
    }

    // Short EWMA should be much higher than long EWMA (trend detected)
    // gradient = long / short < 1.0 (latency degrading)
    $gradient = $metrics['long_ewma_ms'] / $metrics['short_ewma_ms'];

    expect($gradient)->toBeLessThan(1.0);
});
