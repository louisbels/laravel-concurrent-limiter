# Laravel Concurrent Limiter

[![Latest Version on Packagist](https://img.shields.io/packagist/v/largerio/laravel-concurrent-limiter.svg?style=flat-square)](https://packagist.org/packages/largerio/laravel-concurrent-limiter)
[![Tests](https://img.shields.io/github/actions/workflow/status/largerio/laravel-concurrent-limiter/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/largerio/laravel-concurrent-limiter/actions/workflows/run-tests.yml)
[![PHPStan](https://img.shields.io/github/actions/workflow/status/largerio/laravel-concurrent-limiter/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/largerio/laravel-concurrent-limiter/actions/workflows/phpstan.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/largerio/laravel-concurrent-limiter.svg?style=flat-square)](https://packagist.org/packages/largerio/laravel-concurrent-limiter)

A Laravel middleware package that limits the number of **concurrent** requests per user (or IP). Unlike rate limiting which counts requests over time, this package controls how many requests can be processed **simultaneously**.

## Features

- **HTTP Middleware** - Limit concurrent requests per user/IP with automatic queuing
- **Job Middleware** - Limit concurrent queue job execution to protect external APIs
- **Adaptive Limiting** - Auto-adjust limits based on latency using AIMD algorithm
- **Prometheus Metrics** - Built-in `/metrics` endpoint for monitoring
- **Fail-safe** - Configurable behavior when cache is unavailable
- **Events** - Full request lifecycle tracking (wait, acquire, release, exceed)
- **Extensible** - Custom key resolvers and response handlers

## Requirements

- PHP 8.3 or higher
- Laravel 11.x or 12.x
- Cache store with atomic operations (Redis recommended)

## Table of Contents

- [Quick Start](#quick-start)
- [Installation](#installation)
- [HTTP Middleware](#http-middleware)
- [Job Middleware](#job-middleware)
- [Configuration](#configuration)
- [Adaptive Limiting](#adaptive-limiting)
- [Events](#events)
- [Custom Key Resolver](#custom-key-resolver)
- [Custom Response Handler](#custom-response-handler)
- [Prometheus Metrics](#prometheus-metrics)
- [Cache](#cache)
- [Artisan Commands](#artisan-commands)
- [Troubleshooting](#troubleshooting)
- [License](#license)

## Quick Start

```php
// routes/api.php
Route::middleware('concurrent.limit:5,30')->group(function () {
    Route::get('/heavy-endpoint', HeavyController::class);
});
```

This limits each user to **5 concurrent requests**, waiting up to **30 seconds** for a slot before returning 503.

## Installation

Install via Composer:

```bash
composer require largerio/laravel-concurrent-limiter
```

The service provider is auto-discovered. To publish the config file:

```bash
php artisan vendor:publish --provider="Largerio\LaravelConcurrentLimiter\LaravelConcurrentLimiterServiceProvider" --tag="config"
```

## HTTP Middleware

Apply the middleware to routes using the `concurrent.limit` alias:

```php
use Illuminate\Support\Facades\Route;

// Parameters: maxParallel, maxWaitTime, prefix
Route::middleware('concurrent.limit:10,30,api')->group(function () {
    Route::get('/data', [DataController::class, 'index']);
});
```

Or use the static helper:

```php
use Largerio\LaravelConcurrentLimiter\LaravelConcurrentLimiter;

Route::middleware(LaravelConcurrentLimiter::with(10, 30, 'api'))->group(function () {
    // ...
});
```

**How it works:**
1. Generates a unique key based on user ID (or IP if unauthenticated)
2. Increments a counter in cache
3. If over limit, waits (polling every 100ms) until a slot is free
4. If timeout reached, returns 503 with JSON error
5. After processing, decrements the counter

## Job Middleware

Limit concurrent execution of queued jobs to protect external APIs or shared resources:

```php
use Largerio\LaravelConcurrentLimiter\JobConcurrentLimiter;

class ProcessPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function middleware(): array
    {
        return [
            new JobConcurrentLimiter(
                maxParallel: 5,        // Max 5 concurrent jobs
                key: 'stripe-api',     // Shared key for all Stripe jobs
                releaseAfter: 30,      // Retry after 30 seconds if limited
                shouldRelease: true    // Auto-release job back to queue
            ),
        ];
    }

    public function handle(): void
    {
        // Process payment with Stripe...
    }
}
```

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `maxParallel` | int | 5 | Maximum concurrent jobs |
| `key` | string | 'default' | Identifier for grouping jobs |
| `releaseAfter` | int | 30 | Seconds before retrying |
| `shouldRelease` | bool | true | Release job back to queue if limited |

**Use Cases:**
- Limit API calls to third-party services (Stripe, Twilio, etc.)
- Prevent database overload from batch processing
- Control concurrent file processing or exports

## Configuration

| Option | Default | Description |
|--------|---------|-------------|
| `max_parallel` | 10 | Maximum concurrent requests per user |
| `max_wait_time` | 30 | Seconds to wait before returning 503 |
| `ttl_buffer` | 60 | Extra TTL seconds for cache safety |
| `cache_prefix` | `concurrent-limiter:` | Cache key prefix |
| `cache_store` | null | Cache store (null = default) |
| `error_message` | "Too many concurrent..." | 503 response message |
| `retry_after` | true | Include Retry-After header |
| `key_resolver` | null | Custom KeyResolver class |
| `response_handler` | null | Custom ResponseHandler class |
| `on_cache_failure` | 'allow' | Behavior on cache failure: 'allow' or 'reject' |
| `logging.enabled` | false | Log when limits are exceeded |
| `logging.channel` | null | Log channel (null = default) |
| `logging.level` | 'warning' | Log level |
| `metrics.enabled` | false | Enable Prometheus metrics endpoint |
| `metrics.route` | '/concurrent-limiter/metrics' | Metrics endpoint path |
| `metrics.middleware` | [] | Middleware for metrics endpoint |
| `adaptive.enabled` | false | Enable adaptive concurrency limiting |
| `adaptive.algorithm` | 'vegas' | Algorithm: 'vegas' or 'gradient2' |
| `adaptive.min_limit` | 1 | Minimum concurrency limit |
| `adaptive.max_limit` | 100 | Maximum concurrency limit |
| `adaptive.ewma_alpha` | 0.3 | EWMA smoothing factor (Vegas) |
| `adaptive.sample_window` | 60 | Metrics TTL in seconds |
| `adaptive.min_rtt_reset_samples` | 1000 | Reset minRTT after N samples (Vegas) |
| `adaptive.rtt_tolerance` | 2.0 | Acceptable latency multiplier (Gradient2) |

## Adaptive Limiting

Automatically adjust `maxParallel` based on observed response latency using algorithms inspired by Netflix's concurrency-limits library.

### Available Algorithms

**Vegas (default)** - Based on TCP Vegas congestion control:
- Tracks minimum RTT (best-case latency) as baseline
- Compares current latency to baseline to detect queueing
- Uses dynamic alpha/beta thresholds based on current limit
- Best for: Server-side protection, proactive congestion detection

**Gradient2** - Based on EWMA divergence:
- Tracks short-term and long-term EWMA
- Detects latency trends by comparing the two averages
- Configurable tolerance for latency increase
- Best for: Detecting gradual degradation, noisy environments

### Enable Adaptive Limiting

```php
// config/concurrent-limiter.php
'adaptive' => [
    'enabled' => true,
    'algorithm' => 'vegas',         // 'vegas' or 'gradient2'
    'min_limit' => 1,               // Never go below this
    'max_limit' => 100,             // Never exceed this
    'ewma_alpha' => 0.3,            // EWMA smoothing (Vegas)
    'sample_window' => 60,          // Metrics TTL in seconds
    'min_rtt_reset_samples' => 1000, // Reset minRTT after N samples (Vegas)
    'rtt_tolerance' => 2.0,         // Acceptable latency multiplier (Gradient2)
],
```

### How Adaptive Interacts with maxParallel

When adaptive limiting is enabled, the `maxParallel` parameter from your route acts as a **hard cap**:

```php
// Route configuration
Route::middleware('concurrent.limit:10,30,api')->group(...);
//                              ↑
//                              maxParallel = 10 (hard cap)

// Adaptive can only REDUCE the limit, never exceed maxParallel
// Effective limit = min(maxParallel, adaptiveLimit)
```

| Scenario | maxParallel | Adaptive calculates | Effective limit |
|----------|-------------|---------------------|-----------------|
| Good latency | 10 | 15 | **10** (capped) |
| High latency | 10 | 3 | **3** (reduced) |
| No metrics yet | 10 | 10 | **10** (initial) |

This ensures that adaptive limiting is a **safety optimization** - it can reduce load when latency degrades, but never allows more concurrent requests than you explicitly configured.

### Vegas Algorithm Details

**Formula:**
```
gradient = minRTT / avgRTT
queueUse = limit × (1 - gradient)

alpha = max(1, 10% of limit)
beta = max(2, 20% of limit)

if queueUse < alpha → limit++     (room to grow)
if queueUse > beta → limit--      (too much queueing)
else → stable                     (sweet spot)
```

**Example:** With limit=10, minRTT=100ms, avgRTT=100ms:
- gradient = 1.0, queueUse = 0
- 0 < alpha (1) → increase to 11

### Gradient2 Algorithm Details

**Formula:**
```
gradient = longEWMA / shortEWMA

if gradient >= 1.02 → limit++     (clearly improving, with 2% hysteresis)
if gradient < 1/tolerance → limit-- (degrading beyond tolerance)
else → stable                     (within tolerance)
```

**Example:** With tolerance=2.0, shortEWMA=200ms, longEWMA=100ms:
- gradient = 0.5, threshold = 0.5
- 0.5 >= 0.5 → stable (just within tolerance)

### Use Cases

- **Auto-scaling protection**: Automatically reduce concurrency when backend is overloaded
- **Variable workloads**: Handle traffic spikes without manual tuning
- **Proactive detection**: Vegas detects congestion before timeouts occur

### Monitoring

Access metrics programmatically:

```php
use Largerio\LaravelConcurrentLimiter\Contracts\AdaptiveResolver;

$resolver = app(AdaptiveResolver::class);
$metrics = $resolver->getMetrics('concurrent-limiter:api:user123');

// Vegas metrics:
// ['avg_latency_ms' => 245.5, 'min_latency_ms' => 100.0, 'current_limit' => 12, ...]

// Gradient2 metrics:
// ['short_ewma_ms' => 200.0, 'long_ewma_ms' => 150.0, 'current_limit' => 12, ...]
```

## Events

The middleware dispatches events for monitoring and logging:

| Event | When | Properties |
|-------|------|------------|
| `ConcurrentLimitWaitStarted` | Request starts waiting for a slot | `$request`, `$currentCount`, `$maxParallel`, `$key` |
| `ConcurrentLimitAcquired` | Request acquires a slot | `$request`, `$waitedSeconds`, `$key` |
| `ConcurrentLimitExceeded` | Timeout reached, returning 503 | `$request`, `$waitedSeconds`, `$maxParallel`, `$key` |
| `ConcurrentLimitReleased` | Request completed | `$request`, `$totalTime`, `$key` |
| `CacheOperationFailed` | Cache operation fails | `$request` (nullable), `$exception` |

Example listener:

```php
use Largerio\LaravelConcurrentLimiter\Events\ConcurrentLimitExceeded;

class LogConcurrentLimitExceeded
{
    public function handle(ConcurrentLimitExceeded $event): void
    {
        Log::warning('Concurrent limit exceeded', [
            'key' => $event->key,
            'waited_seconds' => $event->waitedSeconds,
            'url' => $event->request->fullUrl(),
        ]);
    }
}
```

## Custom Key Resolver

By default, the middleware uses the authenticated user ID or IP address. Implement `KeyResolver` to customize:

```php
namespace App\Limiters;

use Illuminate\Http\Request;
use Largerio\LaravelConcurrentLimiter\Contracts\KeyResolver;

class TenantKeyResolver implements KeyResolver
{
    public function resolve(Request $request): string
    {
        $tenantId = $request->header('X-Tenant-ID') ?? 'default';
        $userId = $request->user()?->id ?? $request->ip();

        return sha1($tenantId . ':' . $userId);
    }
}
```

Register in config:

```php
// config/concurrent-limiter.php
'key_resolver' => App\Limiters\TenantKeyResolver::class,
```

## Custom Response Handler

Customize the 503 response by implementing `ResponseHandler`:

```php
namespace App\Limiters;

use Illuminate\Http\Request;
use Largerio\LaravelConcurrentLimiter\Contracts\ResponseHandler;
use Symfony\Component\HttpFoundation\Response;

class HtmlResponseHandler implements ResponseHandler
{
    public function handle(Request $request, float $waitedSeconds, int $maxWaitTime): Response
    {
        return response()->view('errors.503-concurrent', [
            'waited' => $waitedSeconds,
            'maxWait' => $maxWaitTime,
        ], 503)->header('Retry-After', (string) $maxWaitTime);
    }
}
```

Register in config:

```php
// config/concurrent-limiter.php
'response_handler' => App\Limiters\HtmlResponseHandler::class,
```

## Prometheus Metrics

Enable Prometheus-compatible metrics for monitoring:

```php
// config/concurrent-limiter.php
'metrics' => [
    'enabled' => true,
    'route' => '/concurrent-limiter/metrics',
    'middleware' => ['auth:api'],
],
```

**Available Metrics:**

| Metric | Type | Description |
|--------|------|-------------|
| `concurrent_limiter_requests_total` | Counter | Total requests processed |
| `concurrent_limiter_exceeded_total` | Counter | Requests rejected (503) |
| `concurrent_limiter_cache_failures_total` | Counter | Cache operation failures |
| `concurrent_limiter_wait_seconds` | Histogram | Time spent waiting for slots |

**Example Output:**

```
# HELP concurrent_limiter_requests_total Total number of requests processed
# TYPE concurrent_limiter_requests_total counter
concurrent_limiter_requests_total{key="all"} 1523

# HELP concurrent_limiter_exceeded_total Total number of requests rejected (503)
# TYPE concurrent_limiter_exceeded_total counter
concurrent_limiter_exceeded_total{key="all"} 42

# HELP concurrent_limiter_wait_seconds Time spent waiting for a slot
# TYPE concurrent_limiter_wait_seconds histogram
concurrent_limiter_wait_seconds_bucket{le="0.1"} 1200
concurrent_limiter_wait_seconds_bucket{le="1"} 1450
concurrent_limiter_wait_seconds_bucket{le="+Inf"} 1523
concurrent_limiter_wait_seconds_sum 156.234
concurrent_limiter_wait_seconds_count 1523
```

**Grafana Tips:**
- Alert on `rate(concurrent_limiter_exceeded_total[5m]) > 10`
- Monitor p99 wait time with histogram quantiles
- Track cache failures for infrastructure issues

## Cache

### Store Recommendations

The middleware requires a cache store that supports atomic operations:

| Cache Store | Production Ready | Notes |
|-------------|------------------|-------|
| **Redis** | Yes | Best choice. Supports locks for atomic operations. |
| **Memcached** | Yes | Good alternative to Redis. |
| **DynamoDB** | Yes | Works with Laravel DynamoDB cache driver. |
| **Database** | Limited | Works but may cause contention under high load. |
| **File** | No | No locking support. Race conditions possible. |
| **Array** | No | Only for testing. Data lost between requests. |

Configure in `config/concurrent-limiter.php`:

```php
'cache_store' => 'redis', // or null to use default
```

### Key Structure

| Context | Pattern | Example |
|---------|---------|---------|
| HTTP requests | `{prefix}{custom_prefix}{user_id\|ip_hash}` | `concurrent-limiter:api:abc123` |
| Job queue | `{prefix}job:{key}` | `concurrent-limiter:job:stripe-api` |
| Locks | `{key}:lock` | `concurrent-limiter:api:abc123:lock` |

### Failure Handling

By default, if cache is unavailable, requests are allowed through (fail-open). For critical endpoints:

```php
// config/concurrent-limiter.php
'on_cache_failure' => 'reject', // Return 503 if cache is unavailable
```

| Mode | Behavior | Use Case |
|------|----------|----------|
| `allow` | Let requests through | General APIs, non-critical endpoints |
| `reject` | Return 503 error | Payment processing, rate-sensitive operations |

## Artisan Commands

### Check Counter Status

```bash
php artisan concurrent-limiter:status {key}

# Example output
Key: concurrent-limiter:abc123...
Current count: 3
Max parallel: 10
Status: 3/10 slots in use
```

### Clear Stuck Counters

```bash
php artisan concurrent-limiter:clear {key} [--force]

# With confirmation
php artisan concurrent-limiter:clear abc123

# Skip confirmation
php artisan concurrent-limiter:clear abc123 --force
```

## Troubleshooting

### Always getting 503 errors

1. **Check `maxParallel` setting** - It might be too low for your traffic
2. **Verify cache is working** - Test with `Cache::put('test', 1); Cache::get('test');`
3. **Check for stuck counters** - They expire after `maxWaitTime + ttl_buffer` seconds

### Requests not being limited

1. **Verify middleware is applied** - Run `php artisan route:list`
2. **Check cache store** - `array` driver doesn't persist between requests
3. **Different users/IPs** - Each user/IP has their own limit

### Performance issues

1. **Use Redis** - Fastest option with proper locking support
2. **Reduce `maxWaitTime`** - Lower wait times free up resources faster
3. **Tune `maxParallel`** - Balance between protection and throughput

### Debugging

Enable logging to see when limits are exceeded:

```php
'logging' => [
    'enabled' => true,
    'channel' => null,
    'level' => 'warning',
],
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for release history.

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).
