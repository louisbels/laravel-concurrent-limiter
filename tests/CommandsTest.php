<?php

use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    config()->set('concurrent-limiter.cache_prefix', 'test:');
});

it('shows status for a key', function () {
    $key = 'test:abc123';
    Cache::put($key, 5, 120);

    $this->artisan('concurrent-limiter:status', ['key' => 'abc123'])
        ->expectsOutputToContain('Key: test:abc123')
        ->expectsOutputToContain('Current count: 5')
        ->assertSuccessful();
});

it('shows status with full key', function () {
    $key = 'test:abc123';
    Cache::put($key, 3, 120);

    $this->artisan('concurrent-limiter:status', ['key' => 'test:abc123'])
        ->expectsOutputToContain('Key: test:abc123')
        ->expectsOutputToContain('Current count: 3')
        ->assertSuccessful();
});

it('shows usage info when no key provided', function () {
    $this->artisan('concurrent-limiter:status')
        ->expectsOutputToContain('Usage:')
        ->assertSuccessful();
});

it('clears a counter with force flag', function () {
    $key = 'test:abc123';
    Cache::put($key, 5, 120);

    $this->artisan('concurrent-limiter:clear', ['key' => 'abc123', '--force' => true])
        ->expectsOutputToContain('has been cleared')
        ->assertSuccessful();

    expect(Cache::get($key, 0))->toBe(0);
});

it('reports when counter is already zero', function () {
    $this->artisan('concurrent-limiter:clear', ['key' => 'nonexistent', '--force' => true])
        ->expectsOutputToContain('already at 0')
        ->assertSuccessful();
});

it('asks for confirmation before clearing', function () {
    $key = 'test:abc123';
    Cache::put($key, 5, 120);

    $this->artisan('concurrent-limiter:clear', ['key' => 'abc123'])
        ->expectsConfirmation('Are you sure you want to clear this counter?', 'yes')
        ->expectsOutputToContain('has been cleared')
        ->assertSuccessful();

    expect(Cache::get($key, 0))->toBe(0);
});

it('cancels clear when user declines', function () {
    $key = 'test:abc123';
    Cache::put($key, 5, 120);

    $this->artisan('concurrent-limiter:clear', ['key' => 'abc123'])
        ->expectsConfirmation('Are you sure you want to clear this counter?', 'no')
        ->expectsOutputToContain('Operation cancelled')
        ->assertSuccessful();

    expect(Cache::get($key, 0))->toBe(5);
});
