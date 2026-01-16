<?php

declare(strict_types=1);

namespace Largerio\LaravelConcurrentLimiter\Contracts;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface ConcurrentLimiter
{
    public function handle(
        Request $request,
        Closure $next,
        ?int $maxParallel = null,
        ?int $maxWaitTime = null,
        string $prefix = ''
    ): Response;
}
