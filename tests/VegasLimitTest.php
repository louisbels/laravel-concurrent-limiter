<?php

use Largerio\LaravelConcurrentLimiter\Adaptive\Algorithms\VegasLimit;

beforeEach(function () {
    config()->set('concurrent-limiter.adaptive.ewma_alpha', 0.3);
    config()->set('concurrent-limiter.adaptive.min_rtt_reset_samples', 1000);
});

it('initializes metrics correctly', function () {
    $vegas = new VegasLimit;

    $metrics = $vegas->getInitialMetrics(100.0, 10);

    expect($metrics)->toMatchArray([
        'avg_latency_ms' => 100.0,
        'min_latency_ms' => 100.0,
        'current_limit' => 10,
        'sample_count' => 1,
    ]);
    expect($metrics)->toHaveKey('updated_at');
});

it('increases limit when queue_use is below alpha', function () {
    $vegas = new VegasLimit;

    // minRTT = avgRTT means gradient = 1.0, queueUse = 0
    // 0 < alpha (min 3) → should increase
    $metrics = [
        'avg_latency_ms' => 100.0,
        'min_latency_ms' => 100.0,  // gradient = 1.0
        'current_limit' => 10,
        'sample_count' => 1,
        'updated_at' => time(),
    ];

    $newLimit = $vegas->calculate($metrics, 10);

    expect($newLimit)->toBe(11);
});

it('decreases limit when queue_use is above beta', function () {
    $vegas = new VegasLimit;

    // With current_limit = 10:
    // alpha = max(3, 10 * 0.1) = 3
    // beta = max(6, 10 * 0.2) = 6
    // Need queueUse > 6, so queueUse = limit * (1 - gradient) > 6
    // With limit = 10: 10 * (1 - gradient) > 6 → gradient < 0.4
    // gradient = minRTT/avgRTT, so minRTT/avgRTT < 0.4
    // e.g., minRTT = 30, avgRTT = 100 → gradient = 0.3
    // queueUse = 10 * (1 - 0.3) = 7 > beta (6) → decrease
    $metrics = [
        'avg_latency_ms' => 100.0,
        'min_latency_ms' => 30.0,  // gradient = 0.3, queueUse = 7
        'current_limit' => 10,
        'sample_count' => 1,
        'updated_at' => time(),
    ];

    $newLimit = $vegas->calculate($metrics, 10);

    expect($newLimit)->toBe(9);
});

it('maintains limit when queue_use is in alpha-beta zone', function () {
    $vegas = new VegasLimit;

    // With current_limit = 10:
    // alpha = max(1, 10 * 0.1) = 1
    // beta = max(2, 10 * 0.2) = 2
    // Need 1 <= queueUse <= 2
    // queueUse = 10 * (1 - gradient)
    // For queueUse = 1.5: gradient = 0.85 (minRTT = 85, avgRTT = 100)
    $metrics = [
        'avg_latency_ms' => 100.0,
        'min_latency_ms' => 85.0,  // gradient = 0.85, queueUse = 1.5
        'current_limit' => 10,
        'sample_count' => 1,
        'updated_at' => time(),
    ];

    $newLimit = $vegas->calculate($metrics, 10);

    expect($newLimit)->toBe(10);  // Stable - no change
});

it('uses dynamic alpha based on current limit', function () {
    $vegas = new VegasLimit;

    // With current_limit = 100:
    // alpha = max(1, 100 * 0.1) = 10
    // beta = max(2, 100 * 0.2) = 20
    // queueUse = 100 * (1 - 1.0) = 0 < alpha (10) → increase
    $metrics = [
        'avg_latency_ms' => 100.0,
        'min_latency_ms' => 100.0,
        'current_limit' => 100,
        'sample_count' => 1,
        'updated_at' => time(),
    ];

    $newLimit = $vegas->calculate($metrics, 100);

    expect($newLimit)->toBe(101);
});

it('uses dynamic beta based on current limit', function () {
    $vegas = new VegasLimit;

    // With current_limit = 100:
    // alpha = 10, beta = 20
    // Need queueUse > 20: 100 * (1 - gradient) > 20 → gradient < 0.8
    // gradient = 0.7 → queueUse = 30 > beta → decrease
    $metrics = [
        'avg_latency_ms' => 100.0,
        'min_latency_ms' => 70.0,  // gradient = 0.7, queueUse = 30
        'current_limit' => 100,
        'sample_count' => 1,
        'updated_at' => time(),
    ];

    $newLimit = $vegas->calculate($metrics, 100);

    expect($newLimit)->toBe(99);
});

it('tracks minimum latency as baseline', function () {
    $vegas = new VegasLimit;

    $metrics = $vegas->getInitialMetrics(100.0, 10);
    expect($metrics['min_latency_ms'])->toBe(100.0);

    // Lower latency updates minRTT
    $metrics = $vegas->updateMetrics($metrics, 50.0);
    expect($metrics['min_latency_ms'])->toBe(50.0);

    // Higher latency doesn't update minRTT
    $metrics = $vegas->updateMetrics($metrics, 200.0);
    expect($metrics['min_latency_ms'])->toBe(50.0);
});

it('updates EWMA correctly', function () {
    $vegas = new VegasLimit;

    $metrics = $vegas->getInitialMetrics(100.0, 10);
    expect($metrics['avg_latency_ms'])->toBe(100.0);

    // EWMA: 0.3 * 200 + 0.7 * 100 = 130
    $metrics = $vegas->updateMetrics($metrics, 200.0);
    expect($metrics['avg_latency_ms'])->toBe(130.0);

    // EWMA: 0.3 * 300 + 0.7 * 130 = 181
    $metrics = $vegas->updateMetrics($metrics, 300.0);
    expect($metrics['avg_latency_ms'])->toBe(181.0);
});

it('resets min_latency after configured samples', function () {
    config()->set('concurrent-limiter.adaptive.min_rtt_reset_samples', 3);

    $vegas = new VegasLimit;

    $metrics = $vegas->getInitialMetrics(100.0, 10);

    // Sample 2: minRTT stays at 50
    $metrics = $vegas->updateMetrics($metrics, 50.0);
    expect($metrics['min_latency_ms'])->toBe(50.0);
    expect($metrics['sample_count'])->toBe(2);

    // Sample 3: Reset triggered (>= 3 samples)
    $metrics = $vegas->updateMetrics($metrics, 200.0);
    expect($metrics['min_latency_ms'])->toBe(200.0);  // Reset to current sample
    expect($metrics['sample_count'])->toBe(1);  // Reset count
});

it('increments sample count', function () {
    $vegas = new VegasLimit;

    $metrics = $vegas->getInitialMetrics(100.0, 10);
    expect($metrics['sample_count'])->toBe(1);

    $metrics = $vegas->updateMetrics($metrics, 200.0);
    expect($metrics['sample_count'])->toBe(2);

    $metrics = $vegas->updateMetrics($metrics, 300.0);
    expect($metrics['sample_count'])->toBe(3);
});

it('handles zero avg latency gracefully', function () {
    $vegas = new VegasLimit;

    $metrics = [
        'avg_latency_ms' => 0.0,
        'min_latency_ms' => 0.0,
        'current_limit' => 10,
        'sample_count' => 1,
        'updated_at' => time(),
    ];

    $newLimit = $vegas->calculate($metrics, 10);

    expect($newLimit)->toBe(10);  // Returns current limit, no division by zero
});

it('handles zero min latency gracefully', function () {
    $vegas = new VegasLimit;

    // When min_latency_ms is 0 but avg is positive, algorithm should return current limit
    // to avoid incorrect gradient calculation (gradient = 0 would cause constant decrease)
    $metrics = [
        'avg_latency_ms' => 100.0,
        'min_latency_ms' => 0.0,  // Edge case: zero min latency
        'current_limit' => 10,
        'sample_count' => 1,
        'updated_at' => time(),
    ];

    $newLimit = $vegas->calculate($metrics, 10);

    expect($newLimit)->toBe(10);  // Returns current limit, avoids incorrect behavior
});

it('calculates gradient correctly', function () {
    $vegas = new VegasLimit;

    // With alpha = max(1, 10 * 0.1) = 1, beta = max(2, 10 * 0.2) = 2
    // gradient = minRTT / avgRTT = 95 / 100 = 0.95
    // queueUse = 10 * (1 - 0.95) = 0.5 < alpha (1) → increase
    $metrics = [
        'avg_latency_ms' => 100.0,
        'min_latency_ms' => 95.0,
        'current_limit' => 10,
        'sample_count' => 1,
        'updated_at' => time(),
    ];

    $newLimit = $vegas->calculate($metrics, 10);

    expect($newLimit)->toBe(11);
});
