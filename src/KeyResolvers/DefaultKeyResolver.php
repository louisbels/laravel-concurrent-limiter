<?php

declare(strict_types=1);

namespace Largerio\LaravelConcurrentLimiter\KeyResolvers;

use Illuminate\Http\Request;
use Largerio\LaravelConcurrentLimiter\Contracts\KeyResolver;
use RuntimeException;

class DefaultKeyResolver implements KeyResolver
{
    public function resolve(Request $request): string
    {
        $user = $request->user();

        if ($user !== null) {
            /** @var string|int $identifier */
            $identifier = $user->getAuthIdentifier();

            return sha1((string) $identifier);
        }

        $ip = $request->ip();

        if ($ip !== null) {
            return sha1($ip);
        }

        throw new RuntimeException('Unable to generate the request signature. No user or IP available.');
    }
}
