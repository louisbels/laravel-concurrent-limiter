<?php

declare(strict_types=1);

namespace Largerio\LaravelConcurrentLimiter\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

class StatusCommand extends Command
{
    protected $signature = 'concurrent-limiter:status {key? : The cache key suffix (without prefix)}';

    protected $description = 'Show current concurrent request counter for a key';

    public function handle(): int
    {
        $keySuffix = $this->argument('key');

        /** @var string $cachePrefix */
        $cachePrefix = config('concurrent-limiter.cache_prefix', 'concurrent-limiter:');

        /** @var string|null $cacheStore */
        $cacheStore = config('concurrent-limiter.cache_store');

        $cache = $cacheStore !== null
            ? Cache::store($cacheStore)
            : Cache::store();

        if ($keySuffix !== null && is_string($keySuffix)) {
            $this->showKeyStatus($cache, $cachePrefix, $keySuffix);

            return self::SUCCESS;
        }

        $this->info('Usage: php artisan concurrent-limiter:status <key>');
        $this->line('');
        $this->line('The key is typically a SHA1 hash of the user ID or IP address.');
        $this->line("Example: php artisan concurrent-limiter:status {$cachePrefix}abc123...");
        $this->line('');
        $this->line('To find active keys, check your cache store directly (e.g., Redis CLI).');

        return self::SUCCESS;
    }

    protected function showKeyStatus(Repository $cache, string $prefix, string $keySuffix): void
    {
        $fullKey = str_starts_with($keySuffix, $prefix)
            ? $keySuffix
            : $prefix.$keySuffix;

        /** @var int $count */
        $count = $cache->get($fullKey, 0);

        /** @var int $maxParallel */
        $maxParallel = config('concurrent-limiter.max_parallel', 10);

        $this->info("Key: {$fullKey}");
        $this->line("Current count: {$count}");
        $this->line("Max parallel: {$maxParallel}");

        if ($count === 0) {
            $this->info('Status: No active requests');
        } elseif ($count <= $maxParallel) {
            $this->info("Status: {$count}/{$maxParallel} slots in use");
        } else {
            $this->warn("Status: Over limit ({$count}/{$maxParallel}) - requests may be queued");
        }
    }
}
