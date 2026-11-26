<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Concerns;

use Cboxdk\StatamicMcp\Integration\Effects;
use Cboxdk\StatamicMcp\Mcp\Support\StacheRepair;
use Cboxdk\StatamicMcp\Mcp\Support\StaticCacheMaintenance;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Search;
use Statamic\Search\Index;
use Statamic\StaticCaching\Cacher;
use Statamic\StaticCaching\Invalidator;

trait ClearsCaches
{
    /**
     * Cache types this call has asked for, run once it is finished.
     *
     * @var list<string>
     */
    private array $pendingCacheClears = [];

    /** @var list<string> */
    private array $pendingStaticUrls = [];

    /** @var list<string> */
    private array $pendingSearchIndexes = [];

    /** @var list<string> */
    private array $pendingRefreshUrls = [];

    /** @var array<string, string> */
    private array $completedCacheClears = [];

    private ?string $writeEffects = null;

    /** What this call's writes did so far: null (none), 'unknown' or 'committed'. */
    protected function writeEffectsSoFar(): ?string
    {
        return $this->writeEffects;
    }

    /** @return array<string, mixed> */
    protected function writeMetadata(bool $success): array
    {
        $meta = [];
        if ($this->writeEffects !== null) {
            $meta['write_effects'] = $this->writeEffects;
            $meta['retry_requires_reconciliation'] = ! $success;
        }
        if ($this->completedCacheClears !== []) {
            $meta['cache'] = $this->completedCacheClears;
        }

        return $meta;
    }

    /**
     * Register cleanup before invoking native events: a saved listener may
     * throw after persistence. Only an explicit false is a Statamic veto.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @param  list<string>|Effects  $cacheTypes
     *
     * @return T
     */
    protected function persistStatamic(callable $write, array|Effects $cacheTypes = ['stache', 'static']): mixed
    {
        $this->authorizePendingWrite();
        $this->clearCachesAfterWrite($cacheTypes);
        // An earlier write that landed stays reported: a failing later step
        // makes the whole call partial, not unknown.
        $this->writeEffects ??= 'unknown';
        $result = $write();
        if ($result === false) {
            throw new \RuntimeException('Statamic refused the write.');
        }

        $this->writeEffects = 'committed';

        return $result;
    }

    /** Routers with a capability grant recheck it at the persistence boundary. */
    protected function authorizePendingWrite(): void {}

    /**
     * Ask for the caches a write affects to be brought up to date once the
     * current tool call is done.
     *
     * The Stache is repaired, not cleared: Statamic keeps the store of the item
     * it saves current, and StacheRepair fixes the indexes elsewhere that the
     * write left stale (see there, and StacheConsistencyTest, which proves the
     * result equals a rebuild from disk). A full clear cost every write about
     * 1 ms per entry on the whole site. 'clear' in the config brings it back.
     *
     * Either way it waits until the call is finished. Clearing **mid-request**
     * is what broke a live site (#53): `statamic:stache:clear` resets the
     * in-memory stores there and then, so the rest of the call read emptied
     * stores, the tree repository returned nothing, Statamic padded the empty
     * tree with every entry at root, and a random entry became the homepage.
     *
     * The static cache is still flushed in full: listing pages, globals and
     * navigation render other records than the one written, and Statamic's
     * invalidator only refreshes the written record's own URL
     * (RenderedCacheMatrixTest).
     *
     * @param  array<int, string>|Effects  $types
     */
    protected function clearCachesAfterWrite(array|Effects $types = ['stache', 'static']): void
    {
        if ($types instanceof Effects) {
            $this->pendingStaticUrls = array_values(array_unique([...$this->pendingStaticUrls, ...$types->staticUrls]));
            $this->pendingSearchIndexes = array_values(array_unique([...$this->pendingSearchIndexes, ...$types->searchIndexes]));
            $types = [...($types->stache ? ['stache'] : []), ...($types->static ? ['static'] : []), ...($types->glide ? ['images'] : [])];
        }
        if (in_array('static', $types, true) && app(StaticCacheMaintenance::class)->mode() === 'refresh') {
            $this->pendingRefreshUrls = array_values(array_unique([
                ...$this->pendingRefreshUrls, ...app(StaticCacheMaintenance::class)->cachedUrls(),
            ]));
        }
        $wanted = array_filter($types, fn (string $type): bool => match ($type) {
            'stache' => $this->stacheAfterWrite() !== 'none',
            'static' => app(StaticCacheMaintenance::class)->mode() !== 'statamic',
            default => true,
        });

        $this->pendingCacheClears = array_values(array_unique([...$this->pendingCacheClears, ...$wanted]));
    }

    /** 'repair' (default), 'clear' (the whole Stache) or 'none'. */
    private function stacheAfterWrite(): string
    {
        $mode = config('statamic.mcp.cache.stache_after_write');
        if (in_array($mode, ['repair', 'clear', 'none'], true)) {
            return $mode;
        }

        // A config published before v4 carries only the old on/off switch.
        return config('statamic.mcp.cache.clear_stache_after_write', true) === false ? 'none' : 'repair';
    }

    /**
     * Run whatever the call asked for. Called once the call is finished.
     *
     * @return array<string, string>
     */
    protected function flushPendingCacheClears(): array
    {
        if ($this->pendingCacheClears === [] && $this->pendingStaticUrls === [] && $this->pendingSearchIndexes === []) {
            return [];
        }

        $types = $this->pendingCacheClears;
        $urls = $this->pendingStaticUrls;
        $indexes = $this->pendingSearchIndexes;
        $refresh = $this->pendingRefreshUrls;
        $this->pendingCacheClears = [];
        $this->pendingStaticUrls = [];
        $this->pendingSearchIndexes = [];
        $this->pendingRefreshUrls = [];

        $results = [];
        if (in_array('stache', $types, true) && $this->stacheAfterWrite() === 'repair') {
            $types = array_values(array_diff($types, ['stache']));
            try {
                app(StacheRepair::class)->apply();
                $results['stache'] = 'repaired';
            } catch (\Throwable $e) {
                // A repair that fails must not leave the Stache stale: fall back
                // to what always works.
                Log::warning('MCP Stache repair failed, clearing the Stache instead: ' . $e->getMessage());
                $types = ['stache', ...$types];
            }
        }

        if (in_array('static', $types, true) && app(StaticCacheMaintenance::class)->alreadyEmptyAfterNativeInvalidation()) {
            $types = array_values(array_diff($types, ['static']));
            $results['static'] = 'cleared';
        }
        $results = [...$results, ...$this->clearStatamicCaches($types)];
        if ($refresh !== []) {
            try {
                // Flush first, so a stopped queue cannot leave stale pages served.
                app(StaticCacheMaintenance::class)->queueWarm($refresh);
                $results['static_refresh'] = 'queued';
            } catch (\Throwable $error) {
                $results['static_refresh'] = 'failed';
                report($error);
            }
        }
        if ($urls !== [] && ! in_array($results['static'] ?? '', ['cleared', 'disabled'], true)) {
            try {
                app(Cacher::class)->invalidateUrls($urls);
                $results['static_urls'] = 'invalidated';
            } catch (\Throwable $error) {
                $results['static_urls'] = 'failed';
                report($error);
            }
        }
        foreach ($indexes as $handle) {
            try {
                $configured = Search::indexes()->whereInstanceOf(Index::class)
                    ->filter(fn (Index $index): bool => $index->handle() === $handle);
                if ($configured->isEmpty()) {
                    throw new \InvalidArgumentException('Unknown search index.');
                }
                foreach ($configured as $index) {
                    $index->update();
                }
                $results['search:' . $handle] = 'updated';
            } catch (\Throwable $error) {
                $results['search:' . $handle] = 'failed';
                report($error);
            }
        }
        $this->completedCacheClears = [...$this->completedCacheClears, ...$results];

        return $results;
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
                $exitCode = match ($type) {
                    'stache' => Artisan::call('statamic:stache:clear'),
                    'static' => Artisan::call('statamic:static:clear'),
                    'images' => Artisan::call('statamic:glide:clear'),
                    'views' => Artisan::call('view:clear'),
                    'application' => Artisan::call('cache:clear'),
                    default => 1,
                };
                $results[$type] = match (true) {
                    $exitCode === 0 => 'cleared',
                    $type === 'static' && ! config('statamic.static_caching.strategy') => 'disabled',
                    default => 'failed',
                };
                if ($results[$type] === 'failed') {
                    Log::warning("MCP cache clear failed for type '{$type}'", ['exit_code' => $exitCode]);
                }
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
