<?php

namespace App\Domain\Catalog\Services;

use Closure;
use Illuminate\Support\Facades\Cache;

/** Defines the CatalogCache class and its project responsibilities. */
class CatalogCache
{
    private const VERSION_KEY = 'vsn:catalog:version';

    /** Handles remember for the catalog cache workflow. */
    public function remember(string $key, int $seconds, Closure $callback): mixed
    {
        return Cache::remember($this->key($key), now()->addSeconds(max(1, $seconds)), $callback);
    }

    /** Handles bump for the catalog cache workflow. */
    public function bump(): int
    {
        $next = $this->version() + 1;
        Cache::forever(self::VERSION_KEY, $next);
        return $next;
    }

    /** Handles version for the catalog cache workflow. */
    public function version(): int
    {
        return max(1, (int) Cache::get(self::VERSION_KEY, 1));
    }

    /** Handles key for the catalog cache workflow. */
    private function key(string $key): string
    {
        return 'vsn:catalog:v'.$this->version().':'.hash('sha256', $key);
    }
}
