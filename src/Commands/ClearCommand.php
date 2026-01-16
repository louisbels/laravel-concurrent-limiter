<?php

declare(strict_types=1);

namespace Largerio\LaravelConcurrentLimiter\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ClearCommand extends Command
{
    protected $signature = 'concurrent-limiter:clear
        {key : The cache key suffix (without prefix)}
        {--force : Skip confirmation}';

    protected $description = 'Clear a stuck concurrent limiter counter';

    public function handle(): int
    {
        /** @var string $keySuffix */
        $keySuffix = $this->argument('key');

        /** @var string $cachePrefix */
        $cachePrefix = config('concurrent-limiter.cache_prefix', 'concurrent-limiter:');

        /** @var string|null $cacheStore */
        $cacheStore = config('concurrent-limiter.cache_store');

        $cache = $cacheStore !== null
            ? Cache::store($cacheStore)
            : Cache::store();

        $fullKey = str_starts_with($keySuffix, $cachePrefix)
            ? $keySuffix
            : $cachePrefix.$keySuffix;

        /** @var int $currentValue */
        $currentValue = $cache->get($fullKey, 0);

        if ($currentValue === 0) {
            $this->info("Key '{$fullKey}' is already at 0 or does not exist.");

            return self::SUCCESS;
        }

        $this->warn("Current value for '{$fullKey}': {$currentValue}");

        if (! $this->option('force') && ! $this->confirm('Are you sure you want to clear this counter?')) {
            $this->line('Operation cancelled.');

            return self::SUCCESS;
        }

        $cache->forget($fullKey);
        $cache->forget($fullKey.':lock');

        $this->info("Counter '{$fullKey}' has been cleared.");

        return self::SUCCESS;
    }
}
