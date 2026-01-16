<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Largerio\LaravelConcurrentLimiter\Contracts\ConcurrentLimiter;
use Largerio\LaravelConcurrentLimiter\Contracts\KeyResolver;
use Largerio\LaravelConcurrentLimiter\Contracts\ResponseHandler;
use Largerio\LaravelConcurrentLimiter\Events\ConcurrentLimitExceeded;
use Largerio\LaravelConcurrentLimiter\Events\ConcurrentLimitReleased;
use Largerio\LaravelConcurrentLimiter\Events\ConcurrentLimitWaiting;
use Largerio\LaravelConcurrentLimiter\LaravelConcurrentLimiter;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    Cache::flush();
    config()->set('concurrent-limiter.cache_prefix', 'test:');
});

it('allows requests within the concurrent limit', function () {
    $middleware = app(LaravelConcurrentLimiter::class);
    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => null);
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]), 5, 10);

    expect($response->getStatusCode())->toBe(200);
    expect($response->getContent())->toBe('{"ok":true}');
});

it('decrements counter after request completes', function () {
    $middleware = app(LaravelConcurrentLimiter::class);
    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => null);
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $key = 'test:'.sha1('127.0.0.1');

    $middleware->handle($request, fn () => response()->json(['ok' => true]), 5, 10);

    expect(Cache::get($key, 0))->toBe(0);
});

it('returns 503 when concurrent limit exceeded and timeout reached', function () {
    $middleware = app(LaravelConcurrentLimiter::class);
    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => null);
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $key = 'test:'.sha1('127.0.0.1');
    Cache::put($key, 10, 120);

    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]), 5, 1);

    expect($response->getStatusCode())->toBe(Response::HTTP_SERVICE_UNAVAILABLE);
    expect(json_decode($response->getContent(), true)['message'])
        ->toBe('Too many concurrent requests. Please try again later.');
});

it('includes Retry-After header when enabled', function () {
    config()->set('concurrent-limiter.retry_after', true);

    $middleware = app(LaravelConcurrentLimiter::class);
    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => null);
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $key = 'test:'.sha1('127.0.0.1');
    Cache::put($key, 10, 120);

    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]), 5, 2);

    expect($response->headers->has('Retry-After'))->toBeTrue();
    expect($response->headers->get('Retry-After'))->toBe('2');
});

it('does not include Retry-After header when disabled', function () {
    config()->set('concurrent-limiter.retry_after', false);

    $middleware = app()->make(LaravelConcurrentLimiter::class);
    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => null);
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $key = 'test:'.sha1('127.0.0.1');
    Cache::put($key, 10, 120);

    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]), 5, 1);

    expect($response->headers->has('Retry-After'))->toBeFalse();
});

it('uses authenticated user id for signature when available', function () {
    $middleware = app(LaravelConcurrentLimiter::class);
    $request = Request::create('/test', 'GET');

    $user = new class
    {
        public function getAuthIdentifier(): int
        {
            return 42;
        }
    };

    $request->setUserResolver(fn () => $user);
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $ipKey = 'test:'.sha1('127.0.0.1');

    $middleware->handle($request, fn () => response()->json(['ok' => true]), 5, 10);

    expect(Cache::get($ipKey, 0))->toBe(0);
});

it('uses prefix for cache key when provided', function () {
    $middleware = app(LaravelConcurrentLimiter::class);
    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => null);
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $keyWithoutPrefix = 'test:'.sha1('127.0.0.1');

    Cache::put($keyWithoutPrefix, 10, 120);

    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]), 5, 10, 'api:');

    expect($response->getStatusCode())->toBe(200);
});

it('throws exception when no user or ip available', function () {
    $middleware = app(LaravelConcurrentLimiter::class);

    $request = Mockery::mock(Request::class);
    $request->shouldReceive('user')->andReturn(null);
    $request->shouldReceive('ip')->andReturn(null);

    $middleware->handle($request, fn () => response()->json(['ok' => true]), 5, 10);
})->throws(RuntimeException::class, 'Unable to generate the request signature');

it('decrements counter even when exception occurs in next closure', function () {
    $middleware = app(LaravelConcurrentLimiter::class);
    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => null);
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $key = 'test:'.sha1('127.0.0.1');

    try {
        $middleware->handle($request, function () {
            throw new Exception('Test exception');
        }, 5, 10);
    } catch (Exception) {
    }

    expect(Cache::get($key, 0))->toBe(0);
});

it('generates correct middleware string with static helper', function () {
    $result = LaravelConcurrentLimiter::with(15, 45, 'custom:');

    expect($result)->toBe(LaravelConcurrentLimiter::class.':15,45,custom:');
});

it('uses config values when parameters not provided', function () {
    config()->set('concurrent-limiter.max_parallel', 3);
    config()->set('concurrent-limiter.max_wait_time', 5);
    config()->set('concurrent-limiter.error_message', 'Custom error');

    $middleware = app(LaravelConcurrentLimiter::class);
    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => null);
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $key = 'test:'.sha1('127.0.0.1');
    Cache::put($key, 10, 120);

    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(Response::HTTP_SERVICE_UNAVAILABLE);
    expect(json_decode($response->getContent(), true)['message'])->toBe('Custom error');
});

it('does not decrement counter below zero', function () {
    $middleware = app(LaravelConcurrentLimiter::class);
    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => null);
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $key = 'test:'.sha1('127.0.0.1');
    Cache::put($key, 0, 120);

    $middleware->handle($request, fn () => response()->json(['ok' => true]), 5, 10);

    expect(Cache::get($key, 0))->toBeGreaterThanOrEqual(0);
});

it('implements ConcurrentLimiter interface', function () {
    $middleware = app(LaravelConcurrentLimiter::class);

    expect($middleware)->toBeInstanceOf(ConcurrentLimiter::class);
});

it('can be resolved via interface from container', function () {
    $middleware = app(ConcurrentLimiter::class);

    expect($middleware)->toBeInstanceOf(LaravelConcurrentLimiter::class);
});

it('uses custom cache store when configured', function () {
    config()->set('concurrent-limiter.cache_store', 'array');

    $middleware = app()->make(LaravelConcurrentLimiter::class);
    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => null);
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]), 5, 10);

    expect($response->getStatusCode())->toBe(200);
});

it('dispatches ConcurrentLimitReleased event after request completes', function () {
    Event::fake([ConcurrentLimitReleased::class]);

    $middleware = app(LaravelConcurrentLimiter::class);
    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => null);
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $middleware->handle($request, fn () => response()->json(['ok' => true]), 5, 10);

    Event::assertDispatched(ConcurrentLimitReleased::class, function ($event) {
        return $event->processingTime >= 0;
    });
});

it('dispatches ConcurrentLimitExceeded event when limit is exceeded', function () {
    Event::fake([ConcurrentLimitExceeded::class]);

    $middleware = app(LaravelConcurrentLimiter::class);
    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => null);
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $key = 'test:'.sha1('127.0.0.1');
    Cache::put($key, 10, 120);

    $middleware->handle($request, fn () => response()->json(['ok' => true]), 5, 1);

    Event::assertDispatched(ConcurrentLimitExceeded::class, function ($event) {
        return $event->waitedSeconds >= 1 && $event->maxParallel === 5;
    });
});

it('dispatches ConcurrentLimitWaiting event when request is queued', function () {
    Event::fake([ConcurrentLimitWaiting::class, ConcurrentLimitExceeded::class]);

    $middleware = app(LaravelConcurrentLimiter::class);
    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => null);
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $key = 'test:'.sha1('127.0.0.1');
    Cache::put($key, 10, 120);

    $middleware->handle($request, fn () => response()->json(['ok' => true]), 5, 1);

    Event::assertDispatched(ConcurrentLimitWaiting::class, function ($event) {
        return $event->currentCount === 11 && $event->maxParallel === 5;
    });
});

it('uses custom key resolver when configured', function () {
    $customResolver = new class implements KeyResolver
    {
        public function resolve(Request $request): string
        {
            return 'custom-key';
        }
    };

    $middleware = new LaravelConcurrentLimiter(null, $customResolver);
    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => null);
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $middleware->handle($request, fn () => response()->json(['ok' => true]), 5, 10);

    expect(Cache::get('test:custom-key', 0))->toBe(0);
});

it('uses custom response handler when configured', function () {
    $customHandler = new class implements ResponseHandler
    {
        public function handle(Request $request, float $waitedSeconds, int $retryAfter): Response
        {
            return response()->json(['custom' => 'response'], 429);
        }
    };

    $middleware = new LaravelConcurrentLimiter(null, null, $customHandler);
    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => null);
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $key = 'test:'.sha1('127.0.0.1');
    Cache::put($key, 10, 120);

    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]), 5, 1);

    expect($response->getStatusCode())->toBe(429);
    expect(json_decode($response->getContent(), true))->toBe(['custom' => 'response']);
});

it('throws exception when maxParallel is less than 1', function () {
    $middleware = app(LaravelConcurrentLimiter::class);
    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => null);
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $middleware->handle($request, fn () => response()->json(['ok' => true]), 0, 10);
})->throws(InvalidArgumentException::class, 'maxParallel must be at least 1');

it('throws exception when maxWaitTime is negative', function () {
    $middleware = app(LaravelConcurrentLimiter::class);
    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => null);
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $middleware->handle($request, fn () => response()->json(['ok' => true]), 5, -1);
})->throws(InvalidArgumentException::class, 'maxWaitTime cannot be negative');

it('allows maxWaitTime of zero', function () {
    $middleware = app(LaravelConcurrentLimiter::class);
    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => null);
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]), 5, 0);

    expect($response->getStatusCode())->toBe(200);
});
