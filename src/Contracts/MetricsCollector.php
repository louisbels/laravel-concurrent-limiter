<?php

declare(strict_types=1);

namespace Largerio\LaravelConcurrentLimiter\Contracts;

interface MetricsCollector
{
    /**
     * Increment the total requests counter.
     */
    public function incrementRequestsTotal(string $key = 'all'): void;

    /**
     * Increment the exceeded requests counter.
     */
    public function incrementExceededTotal(string $key = 'all'): void;

    /**
     * Increment the cache failures counter.
     */
    public function incrementCacheFailuresTotal(): void;

    /**
     * Record wait time in histogram buckets.
     */
    public function recordWaitTime(float $seconds, string $key = 'all'): void;

    /**
     * Set the current active count for a key.
     */
    public function setActiveCount(string $key, int $count): void;

    /**
     * Get all metrics as an array.
     *
     * @return array<string, mixed>
     */
    public function getMetrics(): array;

    /**
     * Format metrics for Prometheus exposition format.
     */
    public function toPrometheusFormat(): string;

    /**
     * Reset all metrics (useful for testing).
     */
    public function reset(): void;
}
