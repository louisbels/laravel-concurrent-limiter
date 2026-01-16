<?php

declare(strict_types=1);

namespace Largerio\LaravelConcurrentLimiter\Metrics;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Largerio\LaravelConcurrentLimiter\Contracts\MetricsCollector;

class PrometheusMetricsCollector implements MetricsCollector
{
    protected Repository $cache;

    protected string $prefix = 'concurrent_limiter_metrics:';

    public function __construct(?Repository $cache = null)
    {
        if ($cache !== null) {
            $this->cache = $cache;

            return;
        }

        /** @var string|null $store */
        $store = config('concurrent-limiter.cache_store');

        $this->cache = $store !== null
            ? Cache::store($store)
            : Cache::store();
    }

    public function incrementRequestsTotal(string $key = 'all'): void
    {
        $this->incrementCounter('requests_total', $key);
    }

    public function incrementExceededTotal(string $key = 'all'): void
    {
        $this->incrementCounter('exceeded_total', $key);
    }

    public function incrementCacheFailuresTotal(): void
    {
        $this->incrementCounter('cache_failures_total', 'all');
    }

    public function recordWaitTime(float $seconds, string $key = 'all'): void
    {
        $buckets = [0.01, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];

        foreach ($buckets as $bucket) {
            if ($seconds <= $bucket) {
                $this->incrementCounter("wait_seconds_bucket_{$bucket}", $key);
            }
        }

        $this->incrementCounter('wait_seconds_bucket_inf', $key);
        $this->addToSum('wait_seconds_sum', $seconds, $key);
        $this->incrementCounter('wait_seconds_count', $key);
    }

    public function setActiveCount(string $key, int $count): void
    {
        $cacheKey = $this->prefix."active:{$key}";
        $this->cache->put($cacheKey, $count, 300);
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetrics(): array
    {
        return [
            'requests_total' => $this->getCounterValues('requests_total'),
            'exceeded_total' => $this->getCounterValues('exceeded_total'),
            'cache_failures_total' => $this->getCounterValue('cache_failures_total', 'all'),
            'wait_seconds' => $this->getHistogramValues(),
        ];
    }

    public function toPrometheusFormat(): string
    {
        $output = [];

        // Requests total
        $output[] = '# HELP concurrent_limiter_requests_total Total number of requests processed';
        $output[] = '# TYPE concurrent_limiter_requests_total counter';
        foreach ($this->getCounterValues('requests_total') as $key => $value) {
            $output[] = "concurrent_limiter_requests_total{key=\"{$key}\"} {$value}";
        }

        // Exceeded total
        $output[] = '';
        $output[] = '# HELP concurrent_limiter_exceeded_total Total number of requests rejected (503)';
        $output[] = '# TYPE concurrent_limiter_exceeded_total counter';
        foreach ($this->getCounterValues('exceeded_total') as $key => $value) {
            $output[] = "concurrent_limiter_exceeded_total{key=\"{$key}\"} {$value}";
        }

        // Cache failures
        $output[] = '';
        $output[] = '# HELP concurrent_limiter_cache_failures_total Total number of cache operation failures';
        $output[] = '# TYPE concurrent_limiter_cache_failures_total counter';
        $cacheFailures = $this->getCounterValue('cache_failures_total', 'all');
        $output[] = "concurrent_limiter_cache_failures_total {$cacheFailures}";

        // Wait time histogram
        $output[] = '';
        $output[] = '# HELP concurrent_limiter_wait_seconds Time spent waiting for a slot';
        $output[] = '# TYPE concurrent_limiter_wait_seconds histogram';

        $buckets = [0.01, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];
        foreach ($buckets as $bucket) {
            $value = $this->getCounterValue("wait_seconds_bucket_{$bucket}", 'all');
            $output[] = "concurrent_limiter_wait_seconds_bucket{le=\"{$bucket}\"} {$value}";
        }
        $infValue = $this->getCounterValue('wait_seconds_bucket_inf', 'all');
        $output[] = "concurrent_limiter_wait_seconds_bucket{le=\"+Inf\"} {$infValue}";

        $sum = $this->getSumValue('wait_seconds_sum', 'all');
        $count = $this->getCounterValue('wait_seconds_count', 'all');
        $output[] = "concurrent_limiter_wait_seconds_sum {$sum}";
        $output[] = "concurrent_limiter_wait_seconds_count {$count}";

        return implode("\n", $output)."\n";
    }

    public function reset(): void
    {
        // Reset is typically not needed for Prometheus metrics
        // but can be useful for testing
        $patterns = [
            'requests_total',
            'exceeded_total',
            'cache_failures_total',
            'wait_seconds_bucket_*',
            'wait_seconds_sum',
            'wait_seconds_count',
            'active',
        ];

        foreach ($patterns as $pattern) {
            $this->cache->forget($this->prefix.$pattern.':all');
        }
    }

    protected function incrementCounter(string $metric, string $key): void
    {
        $cacheKey = $this->prefix.$metric.':'.$key;

        if (! $this->cache->has($cacheKey)) {
            $this->cache->put($cacheKey, 1, 86400);

            return;
        }

        $this->cache->increment($cacheKey);
    }

    protected function addToSum(string $metric, float $value, string $key): void
    {
        $cacheKey = $this->prefix.$metric.':'.$key;

        /** @var float $current */
        $current = $this->cache->get($cacheKey, 0.0);
        $this->cache->put($cacheKey, $current + $value, 86400);
    }

    protected function getCounterValue(string $metric, string $key): int
    {
        $cacheKey = $this->prefix.$metric.':'.$key;

        /** @var int $value */
        $value = $this->cache->get($cacheKey, 0);

        return $value;
    }

    protected function getSumValue(string $metric, string $key): float
    {
        $cacheKey = $this->prefix.$metric.':'.$key;

        /** @var float $value */
        $value = $this->cache->get($cacheKey, 0.0);

        return round($value, 6);
    }

    /**
     * @return array<string, int>
     */
    protected function getCounterValues(string $metric): array
    {
        // For simplicity, we only track 'all' key
        // A more sophisticated implementation would scan cache keys
        return [
            'all' => $this->getCounterValue($metric, 'all'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getHistogramValues(): array
    {
        $buckets = [0.01, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];
        $values = [];

        foreach ($buckets as $bucket) {
            $values["bucket_{$bucket}"] = $this->getCounterValue("wait_seconds_bucket_{$bucket}", 'all');
        }

        $values['bucket_inf'] = $this->getCounterValue('wait_seconds_bucket_inf', 'all');
        $values['sum'] = $this->getSumValue('wait_seconds_sum', 'all');
        $values['count'] = $this->getCounterValue('wait_seconds_count', 'all');

        return $values;
    }
}
