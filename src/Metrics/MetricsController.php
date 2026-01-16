<?php

declare(strict_types=1);

namespace Largerio\LaravelConcurrentLimiter\Metrics;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Largerio\LaravelConcurrentLimiter\Contracts\MetricsCollector;

class MetricsController extends Controller
{
    public function __invoke(MetricsCollector $collector): Response
    {
        return new Response(
            $collector->toPrometheusFormat(),
            200,
            ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']
        );
    }
}
