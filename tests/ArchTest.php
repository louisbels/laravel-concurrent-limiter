<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('contracts are interfaces')
    ->expect('Largerio\LaravelConcurrentLimiter\Contracts')
    ->toBeInterfaces();

arch('events use Dispatchable trait')
    ->expect('Largerio\LaravelConcurrentLimiter\Events')
    ->toUseTrait(Illuminate\Foundation\Events\Dispatchable::class);

arch('key resolvers implement KeyResolver interface')
    ->expect('Largerio\LaravelConcurrentLimiter\KeyResolvers')
    ->toImplement(Largerio\LaravelConcurrentLimiter\Contracts\KeyResolver::class);

arch('response handlers implement ResponseHandler interface')
    ->expect('Largerio\LaravelConcurrentLimiter\ResponseHandlers')
    ->toImplement(Largerio\LaravelConcurrentLimiter\Contracts\ResponseHandler::class);

arch('middleware implements ConcurrentLimiter interface')
    ->expect(Largerio\LaravelConcurrentLimiter\LaravelConcurrentLimiter::class)
    ->toImplement(Largerio\LaravelConcurrentLimiter\Contracts\ConcurrentLimiter::class);

arch('source code has strict types')
    ->expect('Largerio\LaravelConcurrentLimiter')
    ->toUseStrictTypes();

arch('no dependencies on laravel internals')
    ->expect('Largerio\LaravelConcurrentLimiter')
    ->not->toUse(['Illuminate\Support\Facades\DB', 'Illuminate\Support\Facades\Session']);
