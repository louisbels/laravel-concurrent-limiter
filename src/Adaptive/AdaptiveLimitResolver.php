<?php

declare(strict_types=1);

namespace Largerio\LaravelConcurrentLimiter\Adaptive;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Largerio\LaravelConcurrentLimiter\Adaptive\Algorithms\Gradient2Limit;
use Largerio\LaravelConcurrentLimiter\Adaptive\Algorithms\LimitAlgorithm;
use Largerio\LaravelConcurrentLimiter\Adaptive\Algorithms\VegasLimit;
use Largerio\LaravelConcurrentLimiter\Contracts\AdaptiveResolver;

/**
 * Resolves adaptive concurrency limits using configurable algorithms.
 *
 * Supports:
 * - Vegas (default): Based on minRTT/avgRTT ratio, inspired by TCP Vegas
 * - Gradient2: Based on short-term vs long-term EWMA divergence
 *
 * @see https://github.com/Netflix/concurrency-limits
 */
class AdaptiveLimitResolver implements AdaptiveResolver
{
    protected Repository $cache;

    protected LimitAlgorithm $algorithm;

    protected string $cachePrefix = 'concurrent-limiter:adaptive:';

    // Cached config values for performance
    protected bool $enabled;

    protected int $minLimit;

    protected int $maxLimit;

    protected int $sampleWindow;

    public function __construct(?Repository $cache = null, ?LimitAlgorithm $algorithm = null)
    {
        $this->cache = $cache ?? $this->resolveCache();
        $this->algorithm = $algorithm ?? $this->resolveAlgorithm();

        // Cache config values once at instantiation
        $this->enabled = (bool) config('concurrent-limiter.adaptive.enabled', false);

        /** @var int $minLimit */
        $minLimit = config('concurrent-limiter.adaptive.min_limit', 1);
        $this->minLimit = max(1, $minLimit);

        /** @var int $maxLimit */
        $maxLimit = config('concurrent-limiter.adaptive.max_limit', 100);
        $this->maxLimit = max(1, $maxLimit);

        /** @var int $sampleWindow */
        $sampleWindow = config('concurrent-limiter.adaptive.sample_window', 60);
        $this->sampleWindow = max(1, $sampleWindow);

        // Validate config: ensure min_limit <= max_limit
        if ($this->minLimit > $this->maxLimit) {
            throw new \InvalidArgumentException(
                "adaptive.min_limit ({$this->minLimit}) must be <= adaptive.max_limit ({$this->maxLimit})"
            );
        }
    }

    protected function resolveCache(): Repository
    {
        /** @var string|null $store */
        $store = config('concurrent-limiter.cache_store');

        return $store !== null
            ? Cache::store($store)
            : Cache::store();
    }

    protected function resolveAlgorithm(): LimitAlgorithm
    {
        /** @var string $algo */
        $algo = config('concurrent-limiter.adaptive.algorithm', 'vegas');

        return match ($algo) {
            'gradient2' => new Gradient2Limit,
            default => new VegasLimit,
        };
    }

    public function resolve(string $key, int $configuredLimit): int
    {
        if (! $this->enabled) {
            return $configuredLimit;
        }

        $metrics = $this->getMetrics($key);

        if ($metrics === null) {
            return $configuredLimit;
        }

        $newLimit = $this->algorithm->calculate($metrics, $configuredLimit);

        return max($this->minLimit, min($this->maxLimit, $newLimit));
    }

    public function recordLatency(string $key, float $latencyMs, int $currentLimit): void
    {
        if (! $this->enabled) {
            return;
        }

        $cacheKey = $this->getCacheKey($key);

        /** @var array<string, mixed>|null $current */
        $current = $this->cache->get($cacheKey);

        $metrics = $current === null
            ? $this->algorithm->getInitialMetrics($latencyMs, $currentLimit)
            : $this->algorithm->updateMetrics($current, $latencyMs);

        // Calculate and store the new limit
        $metrics['current_limit'] = $this->algorithm->calculate($metrics, $currentLimit);

        // Apply bounds
        $metrics['current_limit'] = max($this->minLimit, min($this->maxLimit, $metrics['current_limit']));

        $this->cache->put($cacheKey, $metrics, $this->sampleWindow);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMetrics(string $key): ?array
    {
        $cacheKey = $this->getCacheKey($key);

        /** @var array<string, mixed>|null $metrics */
        $metrics = $this->cache->get($cacheKey);

        return $metrics;
    }

    protected function getCacheKey(string $key): string
    {
        return $this->cachePrefix.$key;
    }

    /**
     * Get the current algorithm instance.
     */
    public function getAlgorithm(): LimitAlgorithm
    {
        return $this->algorithm;
    }
}
