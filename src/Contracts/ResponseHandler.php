<?php

declare(strict_types=1);

namespace Largerio\LaravelConcurrentLimiter\Contracts;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface ResponseHandler
{
    /**
     * Create a response when the concurrent limit is exceeded.
     */
    public function handle(Request $request, float $waitedSeconds, int $retryAfter): Response;
}
