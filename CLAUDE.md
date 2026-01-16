# CLAUDE.md - AI Assistant Guide

This document provides guidance for AI assistants working with the Laravel Concurrent Limiter codebase.

## Project Overview

**Laravel Concurrent Limiter** is a lightweight Laravel middleware package that limits the number of concurrent requests per user (or IP when unauthenticated). It delays incoming requests until a slot is free or returns a 503 error if the wait exceeds a defined maximum time.

- **Package Name**: `largerio/laravel-concurrent-limiter`
- **Namespace**: `Largerio\LaravelConcurrentLimiter`
- **Repository**: `https://github.com/largerio/laravel-concurrent-limiter`
- **License**: MIT
- **PHP Support**: 8.2, 8.3, 8.4
- **Laravel Support**: 11.x, 12.x

## Repository Structure

```
├── src/
│   ├── Contracts/
│   │   ├── ConcurrentLimiter.php        # Main interface
│   │   ├── KeyResolver.php              # Key resolution interface
│   │   └── ResponseHandler.php          # Response handling interface
│   ├── Events/
│   │   ├── ConcurrentLimitWaiting.php   # Dispatched when request waits
│   │   ├── ConcurrentLimitExceeded.php  # Dispatched on timeout
│   │   └── ConcurrentLimitReleased.php  # Dispatched after completion
│   ├── KeyResolvers/
│   │   └── DefaultKeyResolver.php       # Default: user ID or IP
│   ├── ResponseHandlers/
│   │   └── DefaultResponseHandler.php   # Default: 503 JSON response
│   ├── LaravelConcurrentLimiter.php     # Core middleware class
│   └── LaravelConcurrentLimiterServiceProvider.php
├── tests/
│   ├── ArchTest.php                     # Architecture tests (8 tests)
│   ├── ServiceProviderTest.php          # Service provider tests (5 tests)
│   ├── LaravelConcurrentLimiterTest.php # Middleware tests (20 tests)
│   ├── TestCase.php                     # Base test case
│   └── Pest.php                         # Pest configuration
├── config/
│   └── concurrent-limiter.php           # Package configuration
├── .github/workflows/
│   ├── run-tests.yml                    # Tests (PHP 8.2-8.4, Laravel 10-11)
│   ├── phpstan.yml                      # Static analysis (level 9)
│   └── fix-php-code-style-issues.yml    # Laravel Pint
├── composer.json
├── phpunit.xml.dist
└── phpstan.neon.dist
```

## Development Commands

```bash
composer test           # Run tests (33 tests)
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

### Events

The middleware dispatches three events:

| Event | When | Properties |
|-------|------|------------|
| `ConcurrentLimitWaiting` | Request starts waiting | `$request`, `$currentCount`, `$maxParallel`, `$key` |
| `ConcurrentLimitExceeded` | Timeout reached | `$request`, `$waitedSeconds`, `$maxParallel`, `$key` |
| `ConcurrentLimitReleased` | Request completed | `$request`, `$processingTime`, `$key` |

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

## Code Standards

- **PHP**: PSR-12, `declare(strict_types=1)` on all files
- **Static Analysis**: PHPStan level 9 with strict rules
- **Testing**: Pest PHP with architecture tests
- **Formatting**: Laravel Pint

### Architecture Tests

```php
arch('contracts are interfaces')
arch('events use Dispatchable trait')
arch('key resolvers implement KeyResolver interface')
arch('response handlers implement ResponseHandler interface')
arch('middleware implements ConcurrentLimiter interface')
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
| `run-tests.yml` | PHP 8.2/8.3/8.4 × Laravel 11/12 × Ubuntu/Windows |
| `phpstan.yml` | Static analysis level 9 |
| `fix-php-code-style-issues.yml` | Auto-fix and commit style issues |

## Important Implementation Details

- Cache operations use `LockProvider` when available for atomicity
- Fallback to non-locking operations for simple cache stores
- Counter always decremented in `finally` block to prevent leaks
- TTL = `maxWaitTime + ttl_buffer` to handle crashes
- User ID takes precedence over IP for key generation
