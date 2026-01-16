<?php

use Illuminate\Support\Facades\Cache;
use Largerio\LaravelConcurrentLimiter\JobConcurrentLimiter;

beforeEach(function () {
    Cache::flush();
    config()->set('concurrent-limiter.cache_prefix', 'test:');
});

it('allows jobs within the concurrent limit', function () {
    $middleware = new JobConcurrentLimiter(maxParallel: 5, key: 'test-job');

    $job = new class
    {
        public bool $processed = false;
    };

    $middleware->handle($job, function ($job) {
        $job->processed = true;
    });

    expect($job->processed)->toBeTrue();
});

it('releases job when limit exceeded', function () {
    $key = 'test:job:test-job';
    Cache::put($key, 10, 120);

    $middleware = new JobConcurrentLimiter(maxParallel: 5, key: 'test-job', releaseAfter: 30);

    $job = new class
    {
        public bool $processed = false;

        public int $releaseDelay = 0;

        public function release(int $delay): void
        {
            $this->releaseDelay = $delay;
        }
    };

    $middleware->handle($job, function ($job) {
        $job->processed = true;
    });

    expect($job->processed)->toBeFalse();
    expect($job->releaseDelay)->toBe(30);
});

it('decrements counter after job completes', function () {
    $middleware = new JobConcurrentLimiter(maxParallel: 5, key: 'test-job');

    $job = new class {};

    $middleware->handle($job, function ($job) {
        // Job processing
    });

    $key = 'test:job:test-job';
    expect(Cache::get($key, 0))->toBe(0);
});

it('decrements counter even when job throws exception', function () {
    $middleware = new JobConcurrentLimiter(maxParallel: 5, key: 'test-job');

    $job = new class {};

    try {
        $middleware->handle($job, function ($job) {
            throw new Exception('Job failed');
        });
    } catch (Exception) {
    }

    $key = 'test:job:test-job';
    expect(Cache::get($key, 0))->toBe(0);
});

it('throws exception when maxParallel is less than 1', function () {
    new JobConcurrentLimiter(maxParallel: 0);
})->throws(InvalidArgumentException::class, 'maxParallel must be at least 1');

it('throws exception when releaseAfter is negative', function () {
    new JobConcurrentLimiter(releaseAfter: -1);
})->throws(InvalidArgumentException::class, 'releaseAfter cannot be negative');

it('does not release job when shouldRelease is false', function () {
    $key = 'test:job:test-job';
    Cache::put($key, 10, 120);

    $middleware = new JobConcurrentLimiter(
        maxParallel: 5,
        key: 'test-job',
        releaseAfter: 30,
        shouldRelease: false
    );

    $job = new class
    {
        public bool $processed = false;

        public bool $released = false;

        public function release(int $delay): void
        {
            $this->released = true;
        }
    };

    $middleware->handle($job, function ($job) {
        $job->processed = true;
    });

    expect($job->processed)->toBeFalse();
    expect($job->released)->toBeFalse();
});

it('uses custom cache store when configured', function () {
    config()->set('concurrent-limiter.cache_store', 'array');

    $middleware = new JobConcurrentLimiter(maxParallel: 5, key: 'test-job');

    $job = new class
    {
        public bool $processed = false;
    };

    $middleware->handle($job, function ($job) {
        $job->processed = true;
    });

    expect($job->processed)->toBeTrue();
});
