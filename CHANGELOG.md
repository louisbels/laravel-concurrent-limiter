# Changelog

All notable changes to `laravel-concurrent-limiter` will be documented in this file.

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
