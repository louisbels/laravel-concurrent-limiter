<?php

declare(strict_types=1);

namespace Largerio\LaravelConcurrentLimiter\Contracts;

/**
 * Resolves the adaptive concurrency limit based on observed latency metrics.
 */
interface AdaptiveResolver
{
    /**
     * Resolve the adaptive limit for the given key.
     *
     * @param  string  $key  The cache key identifying the user/IP
     * @param  int  $configuredLimit  The statically configured limit
     * @return int The adjusted limit based on latency metrics
     */
    public function resolve(string $key, int $configuredLimit): int;

    /**
     * Record a latency sample for the given key.
     *
     * @param  string  $key  The cache key identifying the user/IP
     * @param  float  $latencyMs  The observed latency in milliseconds
     * @param  int  $currentLimit  The limit that was used for this request
     */
    public function recordLatency(string $key, float $latencyMs, int $currentLimit): void;

    /**
     * Get the current metrics for the given key.
     *
     * Returns algorithm-specific metrics:
     * - Vegas: avg_latency_ms, min_latency_ms, current_limit, sample_count, updated_at
     * - Gradient2: short_ewma_ms, long_ewma_ms, current_limit, sample_count, updated_at
     *
     * @param  string  $key  The cache key identifying the user/IP
     * @return array{
     *     current_limit: int,
     *     sample_count: int,
     *     updated_at: int,
     *     avg_latency_ms?: float,
     *     min_latency_ms?: float,
     *     short_ewma_ms?: float,
     *     long_ewma_ms?: float
     * }|null
     */
    public function getMetrics(string $key): ?array;
}
