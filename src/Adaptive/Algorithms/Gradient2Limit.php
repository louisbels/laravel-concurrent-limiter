<?php

declare(strict_types=1);

namespace Largerio\LaravelConcurrentLimiter\Adaptive\Algorithms;

/**
 * Gradient2 algorithm for adaptive concurrency limiting.
 *
 * An improvement over VegasLimit that tracks divergence between short-term
 * and long-term EWMA to detect latency trends.
 *
 * - Short EWMA (alpha=0.5): Responsive to recent changes
 * - Long EWMA (alpha=0.1): Stable baseline
 *
 * When short-term EWMA exceeds long-term EWMA, latency is trending worse.
 * The gradient ratio (long/short) determines the adjustment:
 *
 * - gradient >= 1.0: Latency stable or improving → increase limit
 * - gradient < 1/tolerance: Latency degrading beyond tolerance → decrease limit
 * - otherwise: Within tolerance → maintain limit
 *
 * @see https://github.com/Netflix/concurrency-limits
 */
class Gradient2Limit implements LimitAlgorithm
{
    private const SHORT_EWMA_ALPHA = 0.5;

    private const LONG_EWMA_ALPHA = 0.1;

    /**
     * Stability threshold for hysteresis (2% dead zone).
     * When gradient is within [1.0 - threshold, 1.0 + threshold], limit is stable.
     */
    private const STABILITY_THRESHOLD = 0.02;

    /**
     * Calculate the new limit based on EWMA gradient.
     *
     * @param  array{short_ewma_ms: float, long_ewma_ms: float, current_limit: int, sample_count: int, updated_at: int}  $metrics
     */
    public function calculate(array $metrics, int $configuredLimit): int
    {
        $currentLimit = $metrics['current_limit'];

        // Prevent division by zero - both values must be positive
        if ($metrics['short_ewma_ms'] <= 0 || $metrics['long_ewma_ms'] <= 0) {
            return $currentLimit;
        }

        // Gradient: ratio between long-term and short-term EWMA
        // When short > long, we're trending worse (gradient < 1)
        // When long > short, we're trending better (gradient > 1)
        $gradient = $metrics['long_ewma_ms'] / $metrics['short_ewma_ms'];

        /** @var float $tolerance */
        $tolerance = config('concurrent-limiter.adaptive.rtt_tolerance', 2.0);

        // Calculate threshold based on tolerance
        // tolerance=2.0 means we accept up to 2x latency increase
        $threshold = 1.0 / $tolerance;

        // Hysteresis: only increase if clearly improving (gradient > 1.0 + threshold)
        // This prevents constant growth when latency is stable
        if ($gradient >= (1.0 + self::STABILITY_THRESHOLD)) {
            // Latency clearly improving - can grow
            return $currentLimit + 1;
        }

        if ($gradient < $threshold) {
            // Latency degrading beyond tolerance - must shrink
            return max(1, $currentLimit - 1);
        }

        // Within tolerance or stable zone - maintain current limit (hysteresis)
        return $currentLimit;
    }

    /**
     * Update metrics with dual EWMA (short-term and long-term).
     *
     * @param  array{short_ewma_ms: float, long_ewma_ms: float, current_limit: int, sample_count: int, updated_at: int}  $current
     * @return array{short_ewma_ms: float, long_ewma_ms: float, current_limit: int, sample_count: int, updated_at: int}
     */
    public function updateMetrics(array $current, float $latencyMs): array
    {
        // Short EWMA: responsive to recent changes
        $shortEwma = (self::SHORT_EWMA_ALPHA * $latencyMs)
            + ((1 - self::SHORT_EWMA_ALPHA) * $current['short_ewma_ms']);

        // Long EWMA: stable baseline
        $longEwma = (self::LONG_EWMA_ALPHA * $latencyMs)
            + ((1 - self::LONG_EWMA_ALPHA) * $current['long_ewma_ms']);

        return [
            'short_ewma_ms' => $shortEwma,
            'long_ewma_ms' => $longEwma,
            'current_limit' => $current['current_limit'],
            'sample_count' => $current['sample_count'] + 1,
            'updated_at' => time(),
        ];
    }

    /**
     * Initialize metrics with the first sample.
     *
     * @return array{short_ewma_ms: float, long_ewma_ms: float, current_limit: int, sample_count: int, updated_at: int}
     */
    public function getInitialMetrics(float $latencyMs, int $configuredLimit): array
    {
        return [
            'short_ewma_ms' => $latencyMs,
            'long_ewma_ms' => $latencyMs,
            'current_limit' => $configuredLimit,
            'sample_count' => 1,
            'updated_at' => time(),
        ];
    }
}
