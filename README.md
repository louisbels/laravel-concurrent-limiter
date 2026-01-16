# Laravel Concurrent Limiter

[![Latest Version on Packagist](https://img.shields.io/packagist/v/largerio/laravel-concurrent-limiter.svg?style=flat-square)](https://packagist.org/packages/largerio/laravel-concurrent-limiter)
[![Tests](https://img.shields.io/github/actions/workflow/status/largerio/laravel-concurrent-limiter/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/largerio/laravel-concurrent-limiter/actions/workflows/run-tests.yml)
[![PHPStan](https://img.shields.io/github/actions/workflow/status/largerio/laravel-concurrent-limiter/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/largerio/laravel-concurrent-limiter/actions/workflows/phpstan.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/largerio/laravel-concurrent-limiter.svg?style=flat-square)](https://packagist.org/packages/largerio/laravel-concurrent-limiter)

**Laravel Concurrent Limiter** is a Laravel middleware package that limits the number of concurrent requests per user (or IP when unauthenticated). It delays incoming requests until a slot is free or returns a 503 error if the wait exceeds a defined maximum time.

## Installation

You can install the package via Composer:

```bash
composer require largerio/laravel-concurrent-limiter
```

If your Laravel version does not auto-discover the service provider, add it to your `config/app.php` providers array:

```php
Largerio\LaravelConcurrentLimiter\LaravelConcurrentLimiterServiceProvider::class,
```

## Usage

Apply the middleware to your routes using the alias `concurrent.limit`. The middleware accepts three parameters:
- **maxParallel**: Maximum concurrent requests allowed.
- **maxWaitTime**: Maximum time (in seconds) to wait for a slot.
- **prefix**: An optional string to prefix the cache key.

For example, to allow a maximum of 10 parallel requests per user (or IP) and wait up to 30 seconds for a slot:

```php
use Illuminate\Support\Facades\Route;

Route::middleware('concurrent.limit:10,30,api')->group(function () {
    Route::get('/data', [\App\Http\Controllers\DataController::class, 'index']);
});
```

You can also use the static helper to generate the middleware definition:

```php
LaravelConcurrentLimiter::with(10, 30, 'api');
```

## How It Works

When a request enters the middleware, it:
- Generates a unique key based on the authenticated user ID or the request IP.
- Increments a counter in the cache.
- If the counter exceeds the maximum allowed, it waits (checking every 100ms) until a slot is free or the maximum wait time is reached.
- If the wait time is exceeded, it returns a 503 error with a JSON message.

After processing, the counter is decremented.

## Configuration

The package provides a config file that you can publish:

```bash
php artisan vendor:publish --provider="Largerio\LaravelConcurrentLimiter\LaravelConcurrentLimiterServiceProvider" --tag="config"
```

Feel free to customize the default settings.

## Events

The middleware dispatches three events that you can listen to for monitoring and logging:

| Event | When | Properties |
|-------|------|------------|
| `ConcurrentLimitWaiting` | Request starts waiting for a slot | `$request`, `$currentCount`, `$maxParallel`, `$key` |
| `ConcurrentLimitExceeded` | Timeout reached, returning 503 | `$request`, `$waitedSeconds`, `$maxParallel`, `$key` |
| `ConcurrentLimitReleased` | Request completed successfully | `$request`, `$processingTime`, `$key` |

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

By default, the middleware uses the authenticated user ID or the request IP to generate a unique key. You can customize this behavior by implementing your own `KeyResolver`.

Example: Multi-tenant key resolver

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

Register it in your config:

```php
// config/concurrent-limiter.php
'key_resolver' => App\Limiters\TenantKeyResolver::class,
```

## Custom Response Handler

You can customize the 503 response by implementing your own `ResponseHandler`.

Example: HTML response instead of JSON

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

Register it in your config:

```php
// config/concurrent-limiter.php
'response_handler' => App\Limiters\HtmlResponseHandler::class,
```

## Cache Store Recommendations

The middleware requires a cache store that supports atomic operations. Recommendations:

| Cache Store | Production Ready | Notes |
|-------------|------------------|-------|
| **Redis** | ✅ Yes | Best choice. Supports locks for atomic operations. |
| **Memcached** | ✅ Yes | Good alternative to Redis. |
| **DynamoDB** | ✅ Yes | Works with Laravel DynamoDB cache driver. |
| **Database** | ⚠️ Limited | Works but may cause contention under high load. |
| **File** | ❌ No | No locking support. Race conditions possible. |
| **Array** | ❌ No | Only for testing. Data lost between requests. |

Configure your preferred store in `config/concurrent-limiter.php`:

```php
'cache_store' => 'redis', // or null to use default
```

## Artisan Commands

The package includes two Artisan commands for debugging and maintenance:

### Check Counter Status

```bash
php artisan concurrent-limiter:status {key}
```

Shows the current counter value for a given key. The key is typically a SHA1 hash of the user ID or IP address.

```bash
# Example output
Key: concurrent-limiter:abc123...
Current count: 3
Max parallel: 10
Status: 3/10 slots in use
```

### Clear Stuck Counters

```bash
php artisan concurrent-limiter:clear {key} [--force]
```

Clears a stuck counter (e.g., if your app crashed before decrementing). Use `--force` to skip confirmation.

```bash
# With confirmation
php artisan concurrent-limiter:clear abc123

# Skip confirmation
php artisan concurrent-limiter:clear abc123 --force
```

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
                releaseAfter: 30,      // Retry after 30 seconds if limit reached
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

## Prometheus Metrics

Enable Prometheus-compatible metrics for monitoring:

```php
// config/concurrent-limiter.php
'metrics' => [
    'enabled' => true,
    'route' => '/concurrent-limiter/metrics',
    'middleware' => ['auth:api'], // Protect the endpoint
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

**Grafana Dashboard Tips:**
- Alert on `rate(concurrent_limiter_exceeded_total[5m]) > 10`
- Monitor p99 wait time with histogram quantiles
- Track cache failures for infrastructure issues

## Cache Failure Handling

By default, if the cache becomes unavailable (e.g., Redis is down), the middleware will let requests through (fail-open). For critical endpoints, you can configure fail-closed behavior:

```php
// config/concurrent-limiter.php
'on_cache_failure' => 'reject', // Return 503 if cache is unavailable
```

| Mode | Behavior | Use Case |
|------|----------|----------|
| `allow` (default) | Let requests through | General APIs, non-critical endpoints |
| `reject` | Return 503 error | Payment processing, rate-sensitive operations |

The `CacheOperationFailed` event is dispatched when cache errors occur, allowing you to monitor these failures:

```php
use Largerio\LaravelConcurrentLimiter\Events\CacheOperationFailed;

class LogCacheFailure
{
    public function handle(CacheOperationFailed $event): void
    {
        Log::error('Cache operation failed', [
            'exception' => $event->exception->getMessage(),
            'url' => $event->request->fullUrl(),
        ]);
    }
}
```

## Troubleshooting

### Always getting 503 errors

1. **Check your `maxParallel` setting** - It might be too low for your traffic.
2. **Verify cache is working** - Run `php artisan tinker` and test `Cache::put('test', 1); Cache::get('test');`
3. **Check for stuck counters** - If your app crashed, counters may not have decremented. They will expire after `maxWaitTime + ttl_buffer` seconds.

### Requests not being limited

1. **Verify middleware is applied** - Run `php artisan route:list` to check middleware.
2. **Check cache store** - Using `array` driver? It doesn't persist between requests.
3. **Different users/IPs** - Each user/IP has their own limit. Check if requests come from different sources.

### Performance issues

1. **Use Redis** - It's the fastest option with proper locking support.
2. **Tune polling interval** - The middleware polls every 100ms. This is hardcoded but reasonable for most use cases.
3. **Reduce `maxWaitTime`** - Lower wait times free up resources faster.

### Debugging

Enable logging in your config to see when limits are exceeded:

```php
'logging' => [
    'enabled' => true,
    'channel' => null, // uses default channel
    'level' => 'warning',
],
```

You can also listen to events for more detailed monitoring (see Events section above).

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).

---

Happy limiting!
