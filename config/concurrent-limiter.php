<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Maximum Parallel Requests
    |--------------------------------------------------------------------------
    |
    | The maximum number of concurrent requests allowed per user (or IP).
    | Can be overridden per-route via middleware parameters.
    |
    */
    'max_parallel' => 10,

    /*
    |--------------------------------------------------------------------------
    | Maximum Wait Time
    |--------------------------------------------------------------------------
    |
    | Maximum time in seconds to wait for a slot before returning a 503 error.
    | Can be overridden per-route via middleware parameters.
    |
    */
    'max_wait_time' => 30,

    /*
    |--------------------------------------------------------------------------
    | TTL Buffer
    |--------------------------------------------------------------------------
    |
    | Extra seconds added to max_wait_time for the cache TTL.
    | This ensures counters expire even if the application crashes.
    |
    */
    'ttl_buffer' => 60,

    /*
    |--------------------------------------------------------------------------
    | Cache Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix for all cache keys to avoid collisions with other packages.
    |
    */
    'cache_prefix' => 'concurrent-limiter:',

    /*
    |--------------------------------------------------------------------------
    | Cache Store
    |--------------------------------------------------------------------------
    |
    | The cache store to use. Set to null to use the default cache store.
    | For best performance with locking, use 'redis' or 'memcached'.
    |
    */
    'cache_store' => null,

    /*
    |--------------------------------------------------------------------------
    | Error Message
    |--------------------------------------------------------------------------
    |
    | The message returned when the concurrent limit is exceeded.
    |
    */
    'error_message' => 'Too many concurrent requests. Please try again later.',

    /*
    |--------------------------------------------------------------------------
    | Retry-After Header
    |--------------------------------------------------------------------------
    |
    | Whether to include the Retry-After HTTP header in 503 responses.
    | This helps clients know when to retry their request.
    |
    */
    'retry_after' => true,

    /*
    |--------------------------------------------------------------------------
    | Key Resolver
    |--------------------------------------------------------------------------
    |
    | Custom class to resolve the unique key for rate limiting.
    | Must implement Largerio\LaravelConcurrentLimiter\Contracts\KeyResolver.
    | Set to null to use the default resolver (user ID or IP).
    |
    | Example: App\Limiters\TenantKeyResolver::class
    |
    */
    'key_resolver' => null,

    /*
    |--------------------------------------------------------------------------
    | Response Handler
    |--------------------------------------------------------------------------
    |
    | Custom class to handle the response when limit is exceeded.
    | Must implement Largerio\LaravelConcurrentLimiter\Contracts\ResponseHandler.
    | Set to null to use the default JSON response handler.
    |
    | Example: App\Limiters\CustomResponseHandler::class
    |
    */
    'response_handler' => null,

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Configure automatic logging when concurrent limits are exceeded.
    |
    */
    'logging' => [
        'enabled' => false,
        'channel' => null,
        'level' => 'warning',
    ],

    /*
    |--------------------------------------------------------------------------
    | On Cache Failure
    |--------------------------------------------------------------------------
    |
    | Behavior when the cache operation fails (e.g., Redis is down).
    | - 'allow': Let requests through (fail-open, default)
    | - 'reject': Return 503 error (fail-closed, more secure)
    |
    | Use 'reject' for critical endpoints where you'd rather block all
    | requests than risk exceeding your backend capacity.
    |
    */
    'on_cache_failure' => 'allow',

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Configure Prometheus-compatible metrics collection and exposure.
    |
    */
    'metrics' => [
        // Enable metrics collection
        'enabled' => false,

        // Route path for the metrics endpoint (null to disable HTTP endpoint)
        'route' => '/concurrent-limiter/metrics',

        // Middleware to apply to the metrics endpoint (e.g., 'auth:api')
        'middleware' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Adaptive Limiting
    |--------------------------------------------------------------------------
    |
    | Automatically adjust max_parallel based on observed latency using
    | algorithms inspired by Netflix's concurrency-limits library.
    |
    | Available algorithms:
    | - 'vegas': Based on TCP Vegas, uses minRTT/avgRTT ratio to detect queueing
    | - 'gradient2': Tracks divergence between short-term and long-term EWMA
    |
    | @see https://github.com/Netflix/concurrency-limits
    |
    */
    'adaptive' => [
        // Enable adaptive concurrency limiting
        'enabled' => false,

        // Algorithm: 'vegas' (default) or 'gradient2'
        // Vegas: Compares current latency to best-case latency (minRTT)
        // Gradient2: Tracks short-term vs long-term EWMA divergence
        'algorithm' => 'vegas',

        // Minimum concurrency limit (prevents starvation)
        'min_limit' => 1,

        // Maximum concurrency limit (prevents runaway growth)
        'max_limit' => 100,

        // EWMA alpha for latency smoothing (Vegas only, 0.0-1.0)
        // Higher values = more responsive to recent measurements
        // Lower values = smoother, less reactive to spikes
        'ewma_alpha' => 0.3,

        // Time window in seconds for metrics storage
        // Metrics older than this will expire and reset
        'sample_window' => 60,

        // Reset minRTT after N samples (Vegas only)
        // Prevents minRTT from getting stuck at an obsolete value
        'min_rtt_reset_samples' => 1000,

        // RTT tolerance multiplier (Gradient2 only, >= 1.0)
        // 2.0 = accept up to 2x latency increase before reducing limit
        'rtt_tolerance' => 2.0,
    ],
];
