# Changelog

All notable changes to `laravel-concurrent-limiter` will be documented in this file.

## v4.0.0 - 2026-01-17

### Breaking Changes

This release replaces the AIMD algorithm with Netflix-inspired Vegas and Gradient2 algorithms for more accurate congestion detection.

1. **AIMD algorithm removed** - Replaced by Vegas (default) and Gradient2

2. **Config options removed:**
   - `adaptive.target_latency_ms` - No longer used (Vegas calculates automatically)
   - `adaptive.decrease_ratio` - No longer used (Vegas uses dynamic alpha/beta)

3. **Config options added:**
   - `adaptive.algorithm` - Algorithm selection: `'vegas'` (default) or `'gradient2'`
   - `adaptive.min_rtt_reset_samples` - Reset minRTT after N samples (Vegas only, default: 1000)
   - `adaptive.rtt_tolerance` - Acceptable latency multiplier (Gradient2 only, default: 2.0)

4. **Metrics structure changed:**
   - Vegas: Added `min_latency_ms` field
   - Gradient2: Uses `short_ewma_ms` and `long_ewma_ms` instead of `avg_latency_ms`

5. **Latency measurement changed:**
   - Now measures total RTT (wait time + processing time) instead of just processing time
   - This enables proper congestion detection

### Migration from v3.x

```php
// BEFORE (v3.x AIMD)
'adaptive' => [
    'enabled' => true,
    'target_latency_ms' => 500,  // Remove this
    'decrease_ratio' => 0.9,     // Remove this
    'min_limit' => 1,
    'max_limit' => 100,
    'ewma_alpha' => 0.3,
    'sample_window' => 60,
],

// AFTER (v4.0 Vegas)
'adaptive' => [
    'enabled' => true,
    'algorithm' => 'vegas',          // NEW: 'vegas' or 'gradient2'
    'min_limit' => 1,
    'max_limit' => 100,
    'ewma_alpha' => 0.3,             // Vegas only
    'sample_window' => 60,
    'min_rtt_reset_samples' => 1000, // NEW: Vegas only
    'rtt_tolerance' => 2.0,          // NEW: Gradient2 only
],
```

### Added

- **Vegas Algorithm** (default) - TCP Vegas-inspired congestion control
  - Tracks minimum RTT (minRTT) as baseline for best-case latency
  - Calculates `queue_use = limit × (1 - minRTT/avgRTT)` to estimate queueing
  - Dynamic alpha/beta thresholds: `alpha = max(1, 10% of limit)`, `beta = max(2, 20% of limit)`
  - Increases limit when `queue_use < alpha` (room to grow)
  - Decreases limit when `queue_use > beta` (too much queueing)
  - Periodic minRTT reset to adapt to changing conditions
  - Division by zero protection: validates both `avg_latency_ms` and `min_latency_ms` before calculation

- **Gradient2 Algorithm** - EWMA divergence-based alternative
  - Tracks short-term EWMA (α=0.5) and long-term EWMA (α=0.1)
  - Compares the two averages to detect latency trends
  - `gradient = long_ewma / short_ewma`
  - Increases when `gradient >= 1.02` (clearly improving, with 2% hysteresis)
  - Decreases when `gradient < 1/tolerance` (degrading beyond tolerance)
  - Best for detecting gradual degradation in noisy environments
  - Division by zero protection: validates both `short_ewma_ms` and `long_ewma_ms` before calculation
  - `STABILITY_THRESHOLD` constant (0.02) for hysteresis dead zone

- New `LimitAlgorithm` interface for pluggable algorithms:
  - `calculate(array $metrics, int $configuredLimit): int`
  - `updateMetrics(array $currentMetrics, float $latencyMs): array`
  - `getInitialMetrics(float $latencyMs, int $configuredLimit): array`

- New classes:
  - `VegasLimit` - Vegas algorithm implementation
  - `Gradient2Limit` - Gradient2 algorithm implementation

- `AdaptiveLimitResolver::getAlgorithm()` method to inspect current algorithm

- **Config validation** at resolver instantiation:
  - Throws `InvalidArgumentException` if `min_limit > max_limit`
  - Enforces minimum value of 1 for `min_limit`, `max_limit`, and `sample_window`

### Changed

- `AdaptiveLimitResolver` now delegates to configured `LimitAlgorithm`
- `AdaptiveLimitResolver` caches config values at instantiation for performance
- `AdaptiveMetricsCollector` caches config values at instantiation for performance
- **Adaptive limiting now respects `maxParallel` as a hard cap**
  - Effective limit = `min(maxParallel, adaptiveLimit)`
  - Adaptive can only reduce the limit, never exceed the route's `maxParallel`
  - This ensures adaptive is a safety optimization, not a way to exceed configured limits
- `ConcurrentLimitReleased` event now receives total time (wait + processing) instead of just processing time
  - Property renamed from `$processingTime` to `$totalTime`
  - Old property name still works via `__get()` magic method (deprecated, will be removed in v5.0)
- `AdaptiveResolver::getMetrics()` return type updated to support both algorithm-specific metric structures
  - Common fields: `current_limit`, `sample_count`, `updated_at`
  - Vegas fields: `avg_latency_ms`, `min_latency_ms`
  - Gradient2 fields: `short_ewma_ms`, `long_ewma_ms`
- 118 tests (34 new tests for Vegas, Gradient2, integration, config validation, BC compatibility, and maxParallel cap)

### Removed

- `AIMD` algorithm and related code
- `target_latency_ms` config option
- `decrease_ratio` config option

## v3.1.0 - 2026-01-16

### Added

- **Adaptive Limiting** - Automatically adjust `maxParallel` based on observed latency

  - AIMD algorithm (Additive Increase / Multiplicative Decrease) inspired by Netflix's concurrency-limits
  - EWMA (Exponentially Weighted Moving Average) for latency smoothing
  - Configurable target latency, min/max limits, decrease ratio
  - Per-key metrics storage in cache

- New `AdaptiveResolver` interface with methods:
  - `resolve(string $key, int $configuredLimit): int`
  - `recordLatency(string $key, float $latencyMs, int $currentLimit): void`
  - `getMetrics(string $key): ?array`

- New classes:
  - `AdaptiveLimitResolver` - Main adaptive limiting implementation
  - `AdaptiveMetricsCollector` - Event subscriber for latency collection

- New config options:
  - `adaptive.enabled` - Enable/disable adaptive limiting (default: false)
  - `adaptive.target_latency_ms` - Target latency threshold (default: 500)
  - `adaptive.min_limit` - Minimum concurrency limit (default: 1)
  - `adaptive.max_limit` - Maximum concurrency limit (default: 100)
  - `adaptive.decrease_ratio` - Multiplicative decrease factor (default: 0.9)
  - `adaptive.ewma_alpha` - EWMA smoothing factor (default: 0.3)
  - `adaptive.sample_window` - Metrics TTL in seconds (default: 60)

### Changed

- `LaravelConcurrentLimiter` now uses `AdaptiveResolver` to determine effective limit
- Service provider registers `AdaptiveResolver` binding and event subscriber

## v3.0.0 - 2026-01-16

### Breaking Changes

1. **Event renamed**: `ConcurrentLimitWaiting` → `ConcurrentLimitWaitStarted`
   
   - Update your listeners to use the new event name
   
2. **New method in MetricsCollector interface**: `incrementWaitingTotal()`
   
   - Custom implementations must add this method
   

### Added

- `ConcurrentLimitAcquired` event - dispatched when a request acquires a slot
- `incrementWaitingTotal()` method in MetricsCollector interface
- Cache key structure documentation in README

### Fixed

- `request()` call in JobConcurrentLimiter causing RuntimeException in queue context
- Request is now nullable in `CacheOperationFailed` event

### Changed

- `handleWaitStarted()` implemented in MetricsEventSubscriber (was empty)
- Updated CLAUDE.md with correct structure and test counts

### Full Changelog

**Events:**
| Event | When | Properties |
|-------|------|------------|
| `ConcurrentLimitWaitStarted` | Request starts waiting | `$request`, `$currentCount`, `$maxParallel`, `$key` |
| `ConcurrentLimitAcquired` | Request acquires slot | `$request`, `$waitedSeconds`, `$key` |
| `ConcurrentLimitExceeded` | Timeout reached | `$request`, `$waitedSeconds`, `$maxParallel`, `$key` |
| `ConcurrentLimitReleased` | Request completed | `$request`, `$processingTime`, `$key` |
| `CacheOperationFailed` | Cache operation fails | `$request` (nullable), `$exception` |

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
  
  - `ConcurrentLimitWaitStarted` - dispatched when a request starts waiting
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
  
  - `ConcurrentLimitWaitStarted` - dispatched when a request starts waiting
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
