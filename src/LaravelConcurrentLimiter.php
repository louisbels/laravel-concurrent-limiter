<?php

declare(strict_types=1);

namespace Largerio\LaravelConcurrentLimiter;

use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Largerio\LaravelConcurrentLimiter\Adaptive\AdaptiveLimitResolver;
use Largerio\LaravelConcurrentLimiter\Concerns\HasAtomicCacheOperations;
use Largerio\LaravelConcurrentLimiter\Contracts\AdaptiveResolver;
use Largerio\LaravelConcurrentLimiter\Contracts\ConcurrentLimiter;
use Largerio\LaravelConcurrentLimiter\Contracts\KeyResolver;
use Largerio\LaravelConcurrentLimiter\Contracts\ResponseHandler;
use Largerio\LaravelConcurrentLimiter\Events\CacheOperationFailed;
use Largerio\LaravelConcurrentLimiter\Events\ConcurrentLimitAcquired;
use Largerio\LaravelConcurrentLimiter\Events\ConcurrentLimitExceeded;
use Largerio\LaravelConcurrentLimiter\Events\ConcurrentLimitReleased;
use Largerio\LaravelConcurrentLimiter\Events\ConcurrentLimitWaitStarted;
use Largerio\LaravelConcurrentLimiter\KeyResolvers\DefaultKeyResolver;
use Largerio\LaravelConcurrentLimiter\ResponseHandlers\DefaultResponseHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LaravelConcurrentLimiter implements ConcurrentLimiter
{
    use HasAtomicCacheOperations;

    protected CacheManager $cacheManager;

    protected KeyResolver $keyResolver;

    protected ResponseHandler $responseHandler;

    protected AdaptiveResolver $adaptiveResolver;

    public function __construct(
        ?CacheManager $cacheManager = null,
        ?KeyResolver $keyResolver = null,
        ?ResponseHandler $responseHandler = null,
        ?AdaptiveResolver $adaptiveResolver = null
    ) {
        $this->cacheManager = $cacheManager ?? app('cache');
        $this->initializeCache($this->cacheManager);

        $this->keyResolver = $keyResolver ?? $this->resolveKeyResolver();
        $this->responseHandler = $responseHandler ?? $this->resolveResponseHandler();
        $this->adaptiveResolver = $adaptiveResolver ?? new AdaptiveLimitResolver;
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

        // Apply adaptive limiting if enabled
        // Adaptive can only reduce the limit, never exceed maxParallel
        $adaptiveLimit = $this->adaptiveResolver->resolve($key, $maxParallel);
        $maxParallel = min($maxParallel, $adaptiveLimit);
        $startTime = microtime(true);
        $hasWaited = false;

        try {
            $current = $this->atomicIncrement($key, $ttl);
        } catch (Throwable $e) {
            return $this->handleCacheFailure($request, $e, $maxWaitTime);
        }

        while ($current > $maxParallel) {
            if (! $hasWaited) {
                ConcurrentLimitWaitStarted::dispatch($request, $current, $maxParallel, $key);
                $hasWaited = true;
            }

            $elapsed = microtime(true) - $startTime;

            if ($elapsed >= $maxWaitTime) {
                $this->safeDecrement($key);
                $this->logLimitExceeded($request, $elapsed, $key);

                ConcurrentLimitExceeded::dispatch($request, $elapsed, $maxParallel, $key);

                return $this->responseHandler->handle($request, $elapsed, $maxWaitTime);
            }

            usleep(100_000);

            try {
                /** @var int $cachedValue */
                $cachedValue = $this->cache->get($key, 0);
                $current = $cachedValue;
            } catch (Throwable $e) {
                return $this->handleCacheFailure($request, $e, $maxWaitTime);
            }
        }

        $waitedSeconds = microtime(true) - $startTime;
        ConcurrentLimitAcquired::dispatch($request, $waitedSeconds, $key);

        try {
            return $next($request);
        } finally {
            $this->safeDecrement($key);

            // Total time = wait time + processing time (for adaptive limiting RTT)
            $totalTime = microtime(true) - $startTime;

            ConcurrentLimitReleased::dispatch($request, $totalTime, $key);
        }
    }

    protected function handleCacheFailure(Request $request, Throwable $exception, int $maxWaitTime): Response
    {
        CacheOperationFailed::dispatch($request, $exception);

        /** @var string $onCacheFailure */
        $onCacheFailure = config('concurrent-limiter.on_cache_failure', 'allow');

        if ($onCacheFailure === 'reject') {
            return $this->responseHandler->handle($request, 0.0, $maxWaitTime);
        }

        throw $exception;
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
