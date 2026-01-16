<?php

declare(strict_types=1);

namespace Largerio\LaravelConcurrentLimiter\Contracts;

use Closure;

interface JobLimiter
{
    /**
     * Handle the job middleware.
     *
     * @param  Closure(object): void  $next
     */
    public function handle(object $job, Closure $next): void;
}
