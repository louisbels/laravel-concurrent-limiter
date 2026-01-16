<?php

declare(strict_types=1);

namespace Largerio\LaravelConcurrentLimiter;

use Closure;
use Illuminate\Cache\CacheManager;
use InvalidArgumentException;
use Largerio\LaravelConcurrentLimiter\Concerns\HasAtomicCacheOperations;
use Largerio\LaravelConcurrentLimiter\Contracts\JobLimiter;
use Largerio\LaravelConcurrentLimiter\Events\CacheOperationFailed;
use Throwable;

class JobConcurrentLimiter implements JobLimiter
{
    use HasAtomicCacheOperations;

    protected int $maxParallel;

    protected string $key;

    protected int $releaseAfter;

    protected bool $shouldRelease;

    public function __construct(
        ?CacheManager $cacheManager = null,
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

        $this->initializeCache($cacheManager);
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
