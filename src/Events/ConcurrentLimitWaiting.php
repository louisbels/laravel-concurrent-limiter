<?php

declare(strict_types=1);

namespace Largerio\LaravelConcurrentLimiter\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\SerializesModels;

class ConcurrentLimitWaiting
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Request $request,
        public int $currentCount,
        public int $maxParallel,
        public string $key
    ) {}
}
