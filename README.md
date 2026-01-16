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

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).

---

Happy limiting!
