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
];
