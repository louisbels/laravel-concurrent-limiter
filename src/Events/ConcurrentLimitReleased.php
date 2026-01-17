<?php

declare(strict_types=1);

namespace Largerio\LaravelConcurrentLimiter\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\SerializesModels;

class ConcurrentLimitReleased
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  Request  $request  The HTTP request
     * @param  float  $totalTime  Total time in seconds (wait + processing)
     * @param  string  $key  The cache key used for limiting
     */
    public function __construct(
        public Request $request,
        public float $totalTime,
        public string $key
    ) {}

    /**
     * Backward compatibility: access $processingTime as alias for $totalTime.
     *
     * @deprecated Use $totalTime instead. Will be removed in v5.0.
     */
    public function __get(string $name): mixed
    {
        if ($name === 'processingTime') {
            return $this->totalTime;
        }

        throw new \InvalidArgumentException("Property {$name} does not exist on ".self::class);
    }

    /**
     * Backward compatibility: check if $processingTime exists.
     *
     * @deprecated Use $totalTime instead. Will be removed in v5.0.
     */
    public function __isset(string $name): bool
    {
        return $name === 'processingTime';
    }
}
