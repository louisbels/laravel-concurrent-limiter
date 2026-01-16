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
