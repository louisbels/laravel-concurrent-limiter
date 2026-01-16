<?php

declare(strict_types=1);

namespace Largerio\LaravelConcurrentLimiter\Concerns;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

trait HasAtomicCacheOperations
{
    protected Repository $cache;

    protected function initializeCache(?CacheManager $cacheManager = null): void
    {
        $cacheManager = $cacheManager ?? app('cache');

        /** @var string|null $store */
        $store = config('concurrent-limiter.cache_store');

        $this->cache = $store !== null
            ? $cacheManager->store($store)
            : $cacheManager->store();
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
            // Silently ignore decrement failures to avoid breaking the response
        }
    }
}
