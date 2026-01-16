<?php

declare(strict_types=1);

namespace Largerio\LaravelConcurrentLimiter;

use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Largerio\LaravelConcurrentLimiter\Contracts\ConcurrentLimiter;
use Largerio\LaravelConcurrentLimiter\Contracts\KeyResolver;
use Largerio\LaravelConcurrentLimiter\Contracts\ResponseHandler;
use Largerio\LaravelConcurrentLimiter\Events\ConcurrentLimitExceeded;
use Largerio\LaravelConcurrentLimiter\Events\ConcurrentLimitReleased;
use Largerio\LaravelConcurrentLimiter\Events\ConcurrentLimitWaiting;
use Largerio\LaravelConcurrentLimiter\KeyResolvers\DefaultKeyResolver;
use Largerio\LaravelConcurrentLimiter\ResponseHandlers\DefaultResponseHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class LaravelConcurrentLimiter implements ConcurrentLimiter
{
    protected Repository $cache;

    protected CacheManager $cacheManager;

    protected KeyResolver $keyResolver;

    protected ResponseHandler $responseHandler;

    public function __construct(
        ?CacheManager $cacheManager = null,
        ?KeyResolver $keyResolver = null,
        ?ResponseHandler $responseHandler = null
    ) {
        $this->cacheManager = $cacheManager ?? app('cache');

        /** @var string|null $store */
        $store = config('concurrent-limiter.cache_store');

        $this->cache = $store !== null
            ? $this->cacheManager->store($store)
            : $this->cacheManager->store();

        $this->keyResolver = $keyResolver ?? $this->resolveKeyResolver();
        $this->responseHandler = $responseHandler ?? $this->resolveResponseHandler();
    }

    public function handle(
        Request $request,
        Closure $next,
        ?int $maxParallel = null,
        ?int $maxWaitTime = null,
        string $prefix = ''
    ): Response {
        /** @var int $configMaxParallel */
        $configMaxParallel = config('concurrent-limiter.max_parallel', 10);
        /** @var int $configMaxWaitTime */
        $configMaxWaitTime = config('concurrent-limiter.max_wait_time', 30);
        /** @var int $configTtlBuffer */
        $configTtlBuffer = config('concurrent-limiter.ttl_buffer', 60);
        /** @var string $configCachePrefix */
        $configCachePrefix = config('concurrent-limiter.cache_prefix', 'concurrent-limiter:');

        $maxParallel = $maxParallel ?? $configMaxParallel;
        $maxWaitTime = $maxWaitTime ?? $configMaxWaitTime;

        if ($maxParallel < 1) {
            throw new InvalidArgumentException('maxParallel must be at least 1');
        }

        if ($maxWaitTime < 0) {
            throw new InvalidArgumentException('maxWaitTime cannot be negative');
        }

        $ttl = $maxWaitTime + $configTtlBuffer;
        $cachePrefix = $configCachePrefix;

        $key = $cachePrefix.$prefix.$this->keyResolver->resolve($request);
        $startTime = microtime(true);
        $hasWaited = false;

        $current = $this->atomicIncrement($key, $ttl);

        while ($current > $maxParallel) {
            if (! $hasWaited) {
                ConcurrentLimitWaiting::dispatch($request, $current, $maxParallel, $key);
                $hasWaited = true;
            }

            $elapsed = microtime(true) - $startTime;

            if ($elapsed >= $maxWaitTime) {
                $this->atomicDecrement($key);
                $this->logLimitExceeded($request, $elapsed, $key);

                ConcurrentLimitExceeded::dispatch($request, $elapsed, $maxParallel, $key);

                return $this->responseHandler->handle($request, $elapsed, $maxWaitTime);
            }

            usleep(100_000);

            /** @var int $cachedValue */
            $cachedValue = $this->cache->get($key, 0);
            $current = $cachedValue;
        }

        $processingStartTime = microtime(true);

        try {
            return $next($request);
        } finally {
            $this->atomicDecrement($key);
            $processingTime = microtime(true) - $processingStartTime;

            ConcurrentLimitReleased::dispatch($request, $processingTime, $key);
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

    protected function resolveKeyResolver(): KeyResolver
    {
        /** @var class-string<KeyResolver>|null $resolverClass */
        $resolverClass = config('concurrent-limiter.key_resolver');

        if ($resolverClass !== null) {
            return app($resolverClass);
        }

        return new DefaultKeyResolver;
    }

    protected function resolveResponseHandler(): ResponseHandler
    {
        /** @var class-string<ResponseHandler>|null $handlerClass */
        $handlerClass = config('concurrent-limiter.response_handler');

        if ($handlerClass !== null) {
            return app($handlerClass);
        }

        return new DefaultResponseHandler;
    }

    protected function logLimitExceeded(Request $request, float $waitedSeconds, string $key): void
    {
        /** @var array{enabled: bool, channel: string|null, level: string} $loggingConfig */
        $loggingConfig = config('concurrent-limiter.logging', [
            'enabled' => false,
            'channel' => null,
            'level' => 'warning',
        ]);

        if (! $loggingConfig['enabled']) {
            return;
        }

        $channel = $loggingConfig['channel'];
        $level = $loggingConfig['level'];

        $message = sprintf(
            'Concurrent limit exceeded for key [%s] after waiting %.2f seconds',
            $key,
            $waitedSeconds
        );

        $context = [
            'key' => $key,
            'waited_seconds' => $waitedSeconds,
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'ip' => $request->ip(),
        ];

        /** @var LogManager $logManager */
        $logManager = Log::getFacadeRoot();

        /** @var LoggerInterface $logger */
        $logger = $channel !== null ? $logManager->channel($channel) : $logManager;
        $logger->log($level, $message, $context);
    }

    public static function with(int $maxParallel = 10, int $maxWaitTime = 30, string $prefix = ''): string
    {
        return static::class.':'.implode(',', func_get_args());
    }
}
