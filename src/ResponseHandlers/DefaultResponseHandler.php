<?php

declare(strict_types=1);

namespace Largerio\LaravelConcurrentLimiter\ResponseHandlers;

use Illuminate\Http\Request;
use Largerio\LaravelConcurrentLimiter\Contracts\ResponseHandler;
use Symfony\Component\HttpFoundation\Response;

class DefaultResponseHandler implements ResponseHandler
{
    public function handle(Request $request, float $waitedSeconds, int $retryAfter): Response
    {
        $message = config('concurrent-limiter.error_message', 'Too many concurrent requests. Please try again later.');
        $includeRetryAfter = config('concurrent-limiter.retry_after', true);

        $response = response()->json([
            'message' => $message,
        ], Response::HTTP_SERVICE_UNAVAILABLE);

        if ($includeRetryAfter) {
            $response->header('Retry-After', (string) $retryAfter);
        }

        return $response;
    }
}
