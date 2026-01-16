# Changelog

All notable changes to `laravel-concurrent-limiter` will be documented in this file.

## v2.1.0 - 2026-01-16

### Added

- **New trait** `HasAtomicCacheOperations` for shared cache operations
- **New interface** `JobLimiter` for job middleware abstraction
- **New interface** `MetricsCollector` for metrics collection abstraction
- Container bindings for `JobLimiter` and `MetricsCollector` interfaces

### Changed

- `JobConcurrentLimiter` now implements `JobLimiter` interface
- `MetricsCollector` renamed to `PrometheusMetricsCollector`
- `PrometheusMetricsCollector` now implements `MetricsCollector` interface
- Improved dependency injection (constructor accepts dependencies)
- Refactored to eliminate 83 lines of duplicate code

### Removed

- Unused `resources/views/` directory

## v2.0.0 - 2026-01-16

### Added

- **Job Middleware** (`JobConcurrentLimiter`):
  - Limit concurrent execution of queued jobs
  - Parameters: `maxParallel`, `key`, `releaseAfter`, `shouldRelease`
  - Auto-release jobs back to queue when limit exceeded
  - Share limits across job classes using the same key

- **Prometheus Metrics**:
  - `concurrent_limiter_requests_total` counter
  - `concurrent_limiter_exceeded_total` counter
  - `concurrent_limiter_cache_failures_total` counter
  - `concurrent_limiter_wait_seconds` histogram
  - Configurable `/metrics` HTTP endpoint
  - Event subscriber for automatic metric collection

- New config options:
  - `metrics.enabled` - Enable/disable metrics collection
  - `metrics.route` - Custom route path for metrics endpoint
  - `metrics.middleware` - Middleware for metrics endpoint

## v1.3.0 - 2026-01-16

### Added

- **Fail-closed mode**: New `on_cache_failure` config option (`allow`/`reject`) to control behavior when cache is unavailable
- **Artisan commands**:
  - `concurrent-limiter:status {key}` - Check current counter value
  - `concurrent-limiter:clear {key}` - Clear stuck counters
- **CacheOperationFailed event**: Dispatched when cache operations fail, for monitoring

### Changed

- Cache operations now wrapped in try-catch for graceful error handling
- Added `safeDecrement` method for fail-safe counter cleanup

## v1.2.0 - 2026-01-16

### Added

- Parameter validation: `maxParallel` must be >= 1, `maxWaitTime` must be >= 0
- Comprehensive README documentation:
  - Events section with listener examples
  - Custom KeyResolver guide with multi-tenant example
  - Custom ResponseHandler guide with HTML response example
  - Cache store recommendations table
  - Troubleshooting section

### Changed

- Improved error message in `DefaultKeyResolver` with debugging suggestions
- Updated CLAUDE.md with correct PHP/Laravel version references

## v1.1.1 - 2026-01-16

### Removed

- Unused `spatie/laravel-ray` dev dependency

## v1.1.0 - 2026-01-16

### Added

- Laravel 12 support

### Changed

- Minimum PHP version is now 8.3 (dropped PHP 8.2 support)
- Upgraded to Pest 4
- Upgraded to larastan 3.x
- Upgraded phpstan extensions to 2.x

### Removed

- PHP 8.2 support
- Laravel 10 support

## v1.0.0 - 2026-01-16

### Initial Release

#### Added

- Core middleware for concurrent request limiting per user (or IP)
  
- `ConcurrentLimiter` interface for dependency injection
  
- `KeyResolver` interface with `DefaultKeyResolver` (user ID or IP-based)
  
- `ResponseHandler` interface with `DefaultResponseHandler` (503 JSON + Retry-After header)
  
- Events for monitoring:
  
  - `ConcurrentLimitWaiting` - dispatched when a request starts waiting
  - `ConcurrentLimitExceeded` - dispatched when timeout is reached
  - `ConcurrentLimitReleased` - dispatched after request completion
  
- Atomic cache operations with `LockProvider` support
  
- Configurable options: max_parallel, max_wait_time, ttl_buffer, cache_prefix, cache_store, retry_after, key_resolver, response_handler, logging
  
- Static helper `LaravelConcurrentLimiter::with()`
  
- Middleware alias `concurrent.limit`
  
- Support for PHP 8.2, 8.3, 8.4
  
- Support for Laravel 10.x and 11.x
  
- PHPStan level 9 compliance
  
- 33 tests with Pest PHP
  

## [1.1.1] - 2026-01-16

### Removed

- Unused `spatie/laravel-ray` dev dependency

## [1.1.0] - 2026-01-16

### Added

- Laravel 12 support

### Changed

- Minimum PHP version is now 8.3 (dropped PHP 8.2 support)
- Upgraded to Pest 4
- Upgraded to larastan 3.x
- Upgraded phpstan extensions to 2.x

### Removed

- PHP 8.2 support
- Laravel 10 support

## [1.0.0] - 2025-01-16

### Added

- Core middleware for concurrent request limiting per user (or IP)
  
- `ConcurrentLimiter` interface for dependency injection
  
- `KeyResolver` interface with `DefaultKeyResolver` (user ID or IP-based)
  
- `ResponseHandler` interface with `DefaultResponseHandler` (503 JSON + Retry-After header)
  
- Events for monitoring:
  
  - `ConcurrentLimitWaiting` - dispatched when a request starts waiting
  - `ConcurrentLimitExceeded` - dispatched when timeout is reached
  - `ConcurrentLimitReleased` - dispatched after request completion
  
- Atomic cache operations with `LockProvider` support (fallback for non-locking stores)
  
- Configurable options:
  
  - `max_parallel` - maximum concurrent requests
  - `max_wait_time` - timeout before 503 error
  - `ttl_buffer` - extra TTL for cache safety
  - `cache_prefix` - prefix for cache keys
  - `cache_store` - specific cache store
  - `retry_after` - include Retry-After header
  - `key_resolver` - custom key resolver class
  - `response_handler` - custom response handler class
  - `logging` - automatic logging when limits exceeded
  
- Static helper `LaravelConcurrentLimiter::with(maxParallel, maxWaitTime, prefix)`
  
- Middleware alias `concurrent.limit`
  
- Support for PHP 8.2, 8.3, 8.4
  
- Support for Laravel 10.x and 11.x
  
- PHPStan level 9 compliance with strict rules
  
- 33 tests with Pest PHP (architecture, service provider, middleware)
  
- CI/CD with GitHub Actions (tests, PHPStan, Laravel Pint)
  

### Changed

- Namespace from `Patrocle` to `Largerio`
- Package name from `patrocle/laravel-concurrent-limiter` to `largerio/laravel-concurrent-limiter`
