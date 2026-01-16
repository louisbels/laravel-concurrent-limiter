<?php

declare(strict_types=1);

namespace Largerio\LaravelConcurrentLimiter\Contracts;

use Illuminate\Http\Request;

interface KeyResolver
{
    /**
     * Resolve a unique key for the given request.
     *
     * @throws \RuntimeException When unable to generate a key
     */
    public function resolve(Request $request): string;
}
