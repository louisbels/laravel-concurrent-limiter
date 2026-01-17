<?php

declare(strict_types=1);

namespace Largerio\LaravelConcurrentLimiter\Adaptive\Algorithms;

/**
 * Vegas algorithm for adaptive concurrency limiting.
 *
 * Based on TCP Vegas congestion control, this algorithm estimates queue buildup
 * by comparing minimum RTT (best case) with current RTT.
 *
 * Formula: queue_use = limit × (1 - minRTT/avgRTT)
 *
 * - If queue_use < alpha: increase limit (room to grow)
 * - If alpha ≤ queue_use ≤ beta: maintain limit (sweet spot)
 * - If queue_use > beta: decrease limit (too much queueing)
 *
 * Alpha and beta are dynamic: 10% and 20% of current limit respectively.
 *
 * @see https://github.com/Netflix/concurrency-limits
 */
class VegasLimit implements LimitAlgorithm
{
    /**
     * Calculate the new limit based on queue use estimation.
     *
     * @param  array{avg_latency_ms: float, min_latency_ms: float, current_limit: int, sample_count: int, updated_at: int}  $metrics
     */
    public function calculate(array $metrics, int $configuredLimit): int
    {
        $currentLimit = $metrics['current_limit'];

        // Prevent division by zero and handle edge cases
        // Both values must be positive for valid gradient calculation
        if ($metrics['avg_latency_ms'] <= 0 || $metrics['min_latency_ms'] <= 0) {
            return $currentLimit;
        }

        // Gradient: ratio between best-case and current latency
        // gradient = 1.0 means no queueing, < 1.0 means queueing detected
        $gradient = $metrics['min_latency_ms'] / $metrics['avg_latency_ms'];

        // Queue use estimation: how many slots are "waiting" in queue
        $queueUse = $currentLimit * (1 - $gradient);

        // Dynamic alpha/beta based on current limit (Netflix approach)
        // Lower minimums allow proper hysteresis at low limits
        $alpha = max(1, (int) ($currentLimit * 0.1));  // 10% of limit, min 1
        $beta = max(2, (int) ($currentLimit * 0.2));   // 20% of limit, min 2

        if ($queueUse < $alpha) {
            // Room to grow - increase by 1
            return $currentLimit + 1;
        }

        if ($queueUse > $beta) {
            // Too much queueing - decrease by 1
            return $currentLimit - 1;
        }

        // Sweet spot - maintain current limit (hysteresis)
        return $currentLimit;
    }

    /**
     * Update metrics with EWMA and track minimum latency.
     *
     * @param  array{avg_latency_ms: float, min_latency_ms: float, current_limit: int, sample_count: int, updated_at: int}  $current
     * @return array{avg_latency_ms: float, min_latency_ms: float, current_limit: int, sample_count: int, updated_at: int}
     */
    public function updateMetrics(array $current, float $latencyMs): array
    {
        /** @var float $alpha */
        $alpha = config('concurrent-limiter.adaptive.ewma_alpha', 0.3);

        /** @var int $resetSamples */
        $resetSamples = config('concurrent-limiter.adaptive.min_rtt_reset_samples', 1000);

        $sampleCount = $current['sample_count'] + 1;

        // Reset minRTT periodically to adapt to changing conditions
        // This prevents minRTT from getting stuck at an obsolete value
        $shouldReset = $sampleCount >= $resetSamples;

        return [
            'avg_latency_ms' => ($alpha * $latencyMs) + ((1 - $alpha) * $current['avg_latency_ms']),
            'min_latency_ms' => $shouldReset
                ? $latencyMs
                : min($latencyMs, $current['min_latency_ms']),
            'current_limit' => $current['current_limit'],
            'sample_count' => $shouldReset ? 1 : $sampleCount,
            'updated_at' => time(),
        ];
    }

    /**
     * Initialize metrics with the first sample.
     *
     * @return array{avg_latency_ms: float, min_latency_ms: float, current_limit: int, sample_count: int, updated_at: int}
     */
    public function getInitialMetrics(float $latencyMs, int $configuredLimit): array
    {
        return [
            'avg_latency_ms' => $latencyMs,
            'min_latency_ms' => $latencyMs,
            'current_limit' => $configuredLimit,
            'sample_count' => 1,
            'updated_at' => time(),
        ];
    }
}
