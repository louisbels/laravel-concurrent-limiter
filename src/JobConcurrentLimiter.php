<?php

declare(strict_types=1);

namespace Largerio\LaravelConcurrentLimiter;

use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use InvalidArgumentException;
use Largerio\LaravelConcurrentLimiter\Events\CacheOperationFailed;
use Throwable;

class JobConcurrentLimiter
{
    protected Repository $cache;

    protected int $maxParallel;

    protected string $key;

    protected int $releaseAfter;

    protected bool $shouldRelease;

    public function __construct(
        int $maxParallel = 5,
        string $key = 'default',
        int $releaseAfter = 30,
        bool $shouldRelease = true
    ) {
        if ($maxParallel < 1) {
            throw new InvalidArgumentException('maxParallel must be at least 1');
        }

        if ($releaseAfter < 0) {
            throw new InvalidArgumentException('releaseAfter cannot be negative');
        }

        $this->maxParallel = $maxParallel;
        $this->key = $key;
        $this->releaseAfter = $releaseAfter;
        $this->shouldRelease = $shouldRelease;

        /** @var CacheManager $cacheManager */
        $cacheManager = app('cache');

        /** @var string|null $store */
        $store = config('concurrent-limiter.cache_store');

        $this->cache = $store !== null
            ? $cacheManager->store($store)
            : $cacheManager->store();
    }

    /**
     * Handle the job middleware.
     *
     * @param  Closure(object): void  $next
     */
    public function handle(object $job, Closure $next): void
    {
        /** @var string $cachePrefix */
        $cachePrefix = config('concurrent-limiter.cache_prefix', 'concurrent-limiter:');

        /** @var int $ttlBuffer */
        $ttlBuffer = config('concurrent-limiter.ttl_buffer', 60);

        $fullKey = $cachePrefix.'job:'.$this->key;
        $ttl = $this->releaseAfter + $ttlBuffer;

        try {
            $current = $this->atomicIncrement($fullKey, $ttl);
        } catch (Throwable $e) {
            $this->handleCacheFailure($job, $next, $e);

            return;
        }

        if ($current > $this->maxParallel) {
            $this->safeDecrement($fullKey);

            if ($this->shouldRelease && method_exists($job, 'release')) {
                $job->release($this->releaseAfter);
            }

            return;
        }

        try {
            $next($job);
        } finally {
            $this->safeDecrement($fullKey);
        }
    }

    protected function atomicIncrement(string $key, int $ttl): int
    {
        $lock = $this->getLock($key.':lock', 5);

        if ($lock === null) {
            return $this->incrementWithoutLock($key, $ttl);
        }

        /** @var int|null $result */
        $result = $lock->block(5, function () use ($key, $ttl): int {
            if (! $this->cache->has($key)) {
                $this->cache->put($key, 1, $ttl);

                return 1;
            }

            /** @var int $incremented */
            $incremented = $this->cache->increment($key);

            return $incremented;
        });

        return $result ?? $this->incrementWithoutLock($key, $ttl);
    }

    protected function atomicDecrement(string $key): void
    {
        $lock = $this->getLock($key.':lock', 5);

        if ($lock === null) {
            $this->decrementWithoutLock($key);

            return;
        }

        $lock->block(5, function () use ($key): void {
            /** @var int $current */
            $current = $this->cache->get($key, 0);

            if ($current > 0) {
                $this->cache->decrement($key);
            }
        });
    }

    protected function getLock(string $key, int $seconds): ?Lock
    {
        $store = $this->cache->getStore();

        if ($store instanceof LockProvider) {
            return $store->lock($key, $seconds);
        }

        return null;
    }

    protected function incrementWithoutLock(string $key, int $ttl): int
    {
        if (! $this->cache->has($key)) {
            $this->cache->put($key, 1, $ttl);

            return 1;
        }

        /** @var int $incremented */
        $incremented = $this->cache->increment($key);

        return $incremented;
    }

    protected function decrementWithoutLock(string $key): void
    {
        /** @var int $current */
        $current = $this->cache->get($key, 0);

        if ($current > 0) {
            $this->cache->decrement($key);
        }
    }

    protected function safeDecrement(string $key): void
    {
        try {
            $this->atomicDecrement($key);
        } catch (Throwable) {
            // Silently ignore decrement failures
        }
    }

    /**
     * @param  Closure(object): void  $next
     */
    protected function handleCacheFailure(object $job, Closure $next, Throwable $exception): void
    {
        CacheOperationFailed::dispatch(request(), $exception);

        /** @var string $onCacheFailure */
        $onCacheFailure = config('concurrent-limiter.on_cache_failure', 'allow');

        if ($onCacheFailure === 'reject') {
            if ($this->shouldRelease && method_exists($job, 'release')) {
                $job->release($this->releaseAfter);
            }

            return;
        }

        // Fail-open: proceed with job execution
        $next($job);
    }
}
