<?php

declare(strict_types=1);

namespace Largerio\LaravelConcurrentLimiter\Adaptive\Algorithms;

/**
 * Interface for adaptive limit algorithms.
 *
 * Implementations calculate concurrency limits based on latency metrics.
 */
interface LimitAlgorithm
{
    /**
     * Calculate the new limit based on metrics.
     *
     * @param  array<string, mixed>  $metrics  Current metrics from cache
     * @param  int  $configuredLimit  The configured base limit
     * @return int The calculated new limit (before min/max bounds)
     */
    public function calculate(array $metrics, int $configuredLimit): int;

    /**
     * Update metrics with a new latency sample.
     *
     * @param  array<string, mixed>  $currentMetrics  Existing metrics from cache
     * @param  float  $latencyMs  New latency sample in milliseconds
     * @return array<string, mixed> Updated metrics array
     */
    public function updateMetrics(array $currentMetrics, float $latencyMs): array;

    /**
     * Get initial metrics structure for the first sample.
     *
     * @param  float  $latencyMs  First latency sample in milliseconds
     * @param  int  $configuredLimit  The configured base limit
     * @return array<string, mixed> Initial metrics array
     */
    public function getInitialMetrics(float $latencyMs, int $configuredLimit): array;
}
