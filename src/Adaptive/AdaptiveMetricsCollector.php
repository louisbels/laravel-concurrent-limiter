<?php

declare(strict_types=1);

namespace Largerio\LaravelConcurrentLimiter\Adaptive;

use Illuminate\Events\Dispatcher;
use Largerio\LaravelConcurrentLimiter\Contracts\AdaptiveResolver;
use Largerio\LaravelConcurrentLimiter\Events\ConcurrentLimitReleased;

/**
 * Collects latency metrics from events and updates the adaptive resolver.
 */
class AdaptiveMetricsCollector
{
    // Cached config values for performance
    protected bool $enabled;

    protected int $configuredLimit;

    public function __construct(
        protected AdaptiveResolver $resolver
    ) {
        $this->enabled = (bool) config('concurrent-limiter.adaptive.enabled', false);

        /** @var int $configuredLimit */
        $configuredLimit = config('concurrent-limiter.max_parallel', 10);
        $this->configuredLimit = $configuredLimit;
    }

    /**
     * Register the listeners for the subscriber.
     *
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            ConcurrentLimitReleased::class => 'handleReleased',
        ];
    }

    /**
     * Handle the ConcurrentLimitReleased event.
     */
    public function handleReleased(ConcurrentLimitReleased $event): void
    {
        if (! $this->enabled) {
            return;
        }

        $latencyMs = $event->totalTime * 1000;

        $this->resolver->recordLatency($event->key, $latencyMs, $this->configuredLimit);
    }
}
