# CLAUDE.md - AI Assistant Guide

This document provides guidance for AI assistants working with the Laravel Concurrent Limiter codebase.

## Project Overview

**Laravel Concurrent Limiter** is a lightweight Laravel middleware package that limits the number of concurrent requests per user (or IP when unauthenticated). It delays incoming requests until a slot is free or returns a 503 error if the wait exceeds a defined maximum time.

- **Package Name**: `largerio/laravel-concurrent-limiter`
- **Namespace**: `Largerio\LaravelConcurrentLimiter`
- **Repository**: `https://github.com/largerio/laravel-concurrent-limiter`
- **License**: MIT
- **PHP Support**: 8.3, 8.4
- **Laravel Support**: 11.x, 12.x

## Repository Structure

```
├── src/
│   ├── Commands/
│   │   ├── ClearCommand.php             # Clear stuck counters
│   │   └── StatusCommand.php            # Check counter status
│   ├── Concerns/
│   │   └── HasAtomicCacheOperations.php # Shared cache operations trait
│   ├── Adaptive/
│   │   ├── Algorithms/
│   │   │   ├── Gradient2Limit.php       # Gradient2 algorithm (EWMA divergence)
│   │   │   ├── LimitAlgorithm.php       # Algorithm interface
│   │   │   └── VegasLimit.php           # Vegas algorithm (TCP-inspired)
│   │   ├── AdaptiveLimitResolver.php    # Main adaptive resolver
│   │   └── AdaptiveMetricsCollector.php # Event-driven metrics collection
│   ├── Contracts/
│   │   ├── AdaptiveResolver.php         # Adaptive limiting interface
│   │   ├── ConcurrentLimiter.php        # HTTP middleware interface
│   │   ├── JobLimiter.php               # Job middleware interface
│   │   ├── KeyResolver.php              # Key resolution interface
│   │   ├── MetricsCollector.php         # Metrics collection interface
│   │   └── ResponseHandler.php          # Response handling interface
│   ├── Events/
│   │   ├── CacheOperationFailed.php     # Dispatched on cache failure
│   │   ├── ConcurrentLimitAcquired.php  # Dispatched when slot acquired
│   │   ├── ConcurrentLimitExceeded.php  # Dispatched on timeout
│   │   ├── ConcurrentLimitReleased.php  # Dispatched after completion
│   │   └── ConcurrentLimitWaitStarted.php  # Dispatched when request waits
│   ├── KeyResolvers/
│   │   └── DefaultKeyResolver.php       # Default: user ID or IP
│   ├── Metrics/
│   │   ├── MetricsController.php        # Prometheus metrics endpoint
│   │   ├── MetricsEventSubscriber.php   # Event-driven metrics collection
│   │   └── PrometheusMetricsCollector.php # Prometheus format collector
│   ├── ResponseHandlers/
│   │   └── DefaultResponseHandler.php   # Default: 503 JSON response
│   ├── JobConcurrentLimiter.php         # Job queue middleware
│   ├── LaravelConcurrentLimiter.php     # HTTP middleware
│   └── LaravelConcurrentLimiterServiceProvider.php
├── tests/
│   ├── AdaptiveLimitingTest.php         # Adaptive integration tests (23 tests)
│   ├── ArchTest.php                     # Architecture tests (11 tests)
│   ├── CommandsTest.php                 # CLI command tests (7 tests)
│   ├── Gradient2LimitTest.php           # Gradient2 algorithm tests (15 tests)
│   ├── JobConcurrentLimiterTest.php     # Job middleware tests (8 tests)
│   ├── LaravelConcurrentLimiterTest.php # HTTP middleware tests (28 tests)
│   ├── MetricsTest.php                  # Metrics tests (8 tests)
│   ├── ServiceProviderTest.php          # Service provider tests (5 tests)
│   ├── VegasLimitTest.php               # Vegas algorithm tests (13 tests)
│   ├── TestCase.php                     # Base test case
│   └── Pest.php                         # Pest configuration
├── config/
│   └── concurrent-limiter.php           # Package configuration
├── .github/workflows/
│   ├── run-tests.yml                    # Tests (PHP 8.3-8.4, Laravel 11-12)
│   ├── phpstan.yml                      # Static analysis (level 9)
│   └── fix-php-code-style-issues.yml    # Laravel Pint
├── composer.json
├── phpunit.xml.dist
└── phpstan.neon.dist
```

## Development Commands

```bash
composer test           # Run tests (118 tests)
composer test-coverage  # Run tests with coverage
composer analyse        # PHPStan level 9 (strict mode)
composer format         # Laravel Pint code styling
```

## Key Concepts

### Middleware Parameters

```php
// Route-level: maxParallel, maxWaitTime, prefix
Route::middleware('concurrent.limit:10,30,api')->group(...);

// Programmatic helper
Route::middleware(LaravelConcurrentLimiter::with(10, 30, 'api'))->group(...);
```

### Job Middleware

```php
// In your job class
public function middleware(): array
{
    return [
        new JobConcurrentLimiter(
            maxParallel: 5,
            key: 'my-job-type',
            releaseAfter: 30,
            shouldRelease: true
        ),
    ];
}
```

### Events

The middleware dispatches five events:

| Event | When | Properties |
|-------|------|------------|
| `ConcurrentLimitWaitStarted` | Request starts waiting | `$request`, `$currentCount`, `$maxParallel`, `$key` |
| `ConcurrentLimitAcquired` | Request acquires slot | `$request`, `$waitedSeconds`, `$key` |
| `ConcurrentLimitExceeded` | Timeout reached | `$request`, `$waitedSeconds`, `$maxParallel`, `$key` |
| `ConcurrentLimitReleased` | Request completed | `$request`, `$totalTime`, `$key` |
| `CacheOperationFailed` | Cache operation fails | `$request` (nullable), `$exception` |

### Extensibility

Custom key resolution:
```php
// config/concurrent-limiter.php
'key_resolver' => App\Limiters\TenantKeyResolver::class,

// Must implement Largerio\LaravelConcurrentLimiter\Contracts\KeyResolver
```

Custom response handling:
```php
// config/concurrent-limiter.php
'response_handler' => App\Limiters\CustomResponseHandler::class,

// Must implement Largerio\LaravelConcurrentLimiter\Contracts\ResponseHandler
```

## Configuration Options

| Key | Default | Description |
|-----|---------|-------------|
| `max_parallel` | 10 | Max concurrent requests per user/IP |
| `max_wait_time` | 30 | Seconds to wait before 503 error |
| `ttl_buffer` | 60 | Extra TTL seconds for cache safety |
| `cache_prefix` | `concurrent-limiter:` | Cache key prefix |
| `cache_store` | null | Cache store (null = default) |
| `error_message` | "Too many concurrent..." | 503 response message |
| `retry_after` | true | Include Retry-After header |
| `key_resolver` | null | Custom KeyResolver class |
| `response_handler` | null | Custom ResponseHandler class |
| `logging.enabled` | false | Log when limits exceeded |
| `logging.channel` | null | Log channel |
| `logging.level` | warning | Log level |

## Adaptive Limiting (v4.0+)

Automatically adjust `maxParallel` based on observed response latency.

### Algorithms

| Algorithm | Description | Best For |
|-----------|-------------|----------|
| **Vegas** (default) | TCP Vegas-inspired, tracks minRTT/avgRTT ratio | Server-side protection, proactive congestion detection |
| **Gradient2** | EWMA divergence, compares short/long term averages | Detecting gradual degradation, noisy environments |

### Vegas Algorithm

```
gradient = minRTT / avgRTT
queueUse = limit × (1 - gradient)

alpha = max(1, 10% of limit)
beta = max(2, 20% of limit)

if queueUse < alpha → limit++     (room to grow)
if queueUse > beta → limit--      (too much queueing)
else → stable                     (sweet spot)
```

### Gradient2 Algorithm

```
gradient = longEWMA / shortEWMA

if gradient >= 1.02 → limit++     (clearly improving, with 2% hysteresis)
if gradient < 1/tolerance → limit-- (degrading beyond tolerance)
else → stable                     (within tolerance)
```

### Adaptive Config Options

| Key | Default | Description |
|-----|---------|-------------|
| `adaptive.enabled` | false | Enable adaptive limiting |
| `adaptive.algorithm` | 'vegas' | Algorithm: 'vegas' or 'gradient2' |
| `adaptive.min_limit` | 1 | Minimum concurrency limit |
| `adaptive.max_limit` | 100 | Maximum concurrency limit |
| `adaptive.ewma_alpha` | 0.3 | EWMA smoothing factor (Vegas) |
| `adaptive.sample_window` | 60 | Metrics TTL in seconds |
| `adaptive.min_rtt_reset_samples` | 1000 | Reset minRTT after N samples (Vegas) |
| `adaptive.rtt_tolerance` | 2.0 | Acceptable latency multiplier (Gradient2) |

### Key Classes

- `AdaptiveLimitResolver` - Main resolver, delegates to configured algorithm
- `AdaptiveMetricsCollector` - Event subscriber, records latency from `ConcurrentLimitReleased`
- `VegasLimit` - Vegas algorithm implementation
- `Gradient2Limit` - Gradient2 algorithm implementation
- `LimitAlgorithm` - Interface for pluggable algorithms

### maxParallel as Hard Cap

When adaptive is enabled, the route's `maxParallel` acts as a hard cap:

```php
// concurrent.limit:10 → maxParallel = 10
// Effective limit = min(maxParallel, adaptiveLimit)
// Adaptive can reduce to min_limit, but never exceed maxParallel
```

## Code Standards

- **PHP**: PSR-12, `declare(strict_types=1)` on all files
- **Static Analysis**: PHPStan level 9 with strict rules
- **Testing**: Pest PHP with architecture tests
- **Formatting**: Laravel Pint

### Architecture Tests

```php
arch('contracts are interfaces')
arch('concerns are traits')
arch('events use Dispatchable trait')
arch('key resolvers implement KeyResolver interface')
arch('response handlers implement ResponseHandler interface')
arch('middleware implements ConcurrentLimiter interface')
arch('job middleware implements JobLimiter interface')
arch('prometheus metrics collector implements MetricsCollector interface')
arch('source code has strict types')
arch('no debugging functions')
arch('no dependencies on laravel internals')
```

## Request Flow

1. Request enters middleware
2. Atomic increment of cache counter (with lock if available)
3. If over limit: wait loop (100ms polling) until slot free or timeout
4. On timeout: decrement, dispatch `ConcurrentLimitExceeded`, return 503
5. On success: process request, decrement in `finally`, dispatch `ConcurrentLimitReleased`

## CI/CD Pipeline

All workflows trigger on `push` AND `pull_request`:

| Workflow | Description |
|----------|-------------|
| `run-tests.yml` | PHP 8.3/8.4 × Laravel 11/12 × Ubuntu/Windows |
| `phpstan.yml` | Static analysis level 9 |
| `fix-php-code-style-issues.yml` | Auto-fix and commit style issues |

## Important Implementation Details

- Cache operations use `LockProvider` when available for atomicity
- Fallback to non-locking operations for simple cache stores
- Counter always decremented in `finally` block to prevent leaks
- TTL = `maxWaitTime + ttl_buffer` to handle crashes
- User ID takes precedence over IP for key generation
