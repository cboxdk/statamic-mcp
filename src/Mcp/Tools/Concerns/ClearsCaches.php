<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Concerns;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Statamic\StaticCaching\Invalidator;

trait ClearsCaches
{
    /**
     * Cache types this call has asked for, run once it is finished.
     *
     * @var list<string>
     */
    private array $pendingCacheClears = [];

    /**
     * Ask for a cache clear once the current tool call is done.
     *
     * The timing is the whole fix for #53, and it is worth being precise about
     * why. Clearing after a write was never wrong in itself — Statamic does not
     * rebuild the indexes that *depend* on a write, so without it a changed
     * max_items leaves an index of arrays that later throws, a removed taxonomy
     * leaves whereTaxonomy() returning entries, a changed mount leaves every
     * entry 404ing. That set is long, version-dependent, and was not exhausted by
     * repeated attempts to enumerate it — every pass over the code turned up
     * another one.
     *
     * What was wrong was clearing **mid-request**. `statamic:stache:clear` runs
     * through Artisan and resets the in-memory stores there and then, so the
     * rest of the call read from emptied stores: on a live multisite the tree
     * repository returned nothing, CollectionStructure::validateTree() padded
     * the empty tree with every entry at root, and a random entry became the
     * homepage.
     *
     * Deferring to the end of the call keeps the coverage and removes the
     * mechanism. By then the response is built and nothing further reads
     * Statamic; the next call starts fresh, with Blink flushed too.
     *
     * @param  array<int, string>  $types
     */
    protected function clearCachesAfterWrite(array $types = ['stache', 'static']): void
    {
        $wanted = array_filter($types, fn (string $type): bool => match ($type) {
            'stache' => (bool) config('statamic.mcp.cache.clear_stache_after_write', true),
            'static' => (bool) config('statamic.mcp.cache.clear_static_after_write', true),
            default => true,
        });

        $this->pendingCacheClears = array_values(array_unique([...$this->pendingCacheClears, ...$wanted]));
    }

    /**
     * Run whatever the call asked for. Called once the call is finished.
     *
     * @return array<string, string>
     */
    protected function flushPendingCacheClears(): array
    {
        if ($this->pendingCacheClears === []) {
            return [];
        }

        $types = $this->pendingCacheClears;
        $this->pendingCacheClears = [];

        return $this->clearStatamicCaches($types);
    }

    /**
     * Invalidate the static cache for one item, the way Statamic does.
     *
     * Statamic's StaticCaching\Invalidate subscribes to saved events for
     * entries, terms, globals, navs, forms, assets, blueprints and collection
     * *trees* — but not to CollectionSaved. So changing a collection's template
     * or layout invalidates nothing on its own.
     *
     * DefaultInvalidator already knows how to turn a Collection into URLs, so
     * this is Statamic's own targeted path. Best-effort: static caching may be
     * off, or the binding absent.
     */
    protected function invalidateStaticCache(mixed $item): void
    {
        try {
            if (! app()->bound(Invalidator::class)) {
                return;
            }

            app(Invalidator::class)->invalidate($item);
        } catch (\Throwable $e) {
            Log::warning('MCP static cache invalidation failed: ' . $e->getMessage());
        }
    }

    /**
     * Clear caches now.
     *
     * The explicit, caller-requested clear — the system router's cache_clear
     * action. Immediate on purpose: someone asked for it.
     *
     * Cache clearing is best-effort — failures are logged but do not halt execution.
     *
     * @param  array<int, string>  $types
     *
     * @return array<string, string>
     */
    protected function clearStatamicCaches(array $types = ['stache', 'static']): array
    {
        $results = [];

        foreach ($types as $type) {
            try {
                match ($type) {
                    'stache' => Artisan::call('statamic:stache:clear'),
                    'static' => Artisan::call('statamic:static:clear'),
                    'images' => Artisan::call('statamic:glide:clear'),
                    'views' => Artisan::call('view:clear'),
                    'application' => Artisan::call('cache:clear'),
                    default => null,
                };
                $results[$type] = 'cleared';
            } catch (\Throwable $e) {
                // Throwable, not Exception: a StacheCleared listener raising a
                // TypeError would otherwise escape and cost the caller its
                // whole response envelope over a cache rebuild.
                $results[$type] = 'failed';
                Log::warning("MCP cache clear failed for type '{$type}': {$e->getMessage()}");
            }
        }

        return $results;
    }
}
