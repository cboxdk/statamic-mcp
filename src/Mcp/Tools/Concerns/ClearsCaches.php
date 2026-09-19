<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Concerns;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Statamic\StaticCaching\Invalidator;

trait ClearsCaches
{
    /**
     * Clear caches after a write, for the kinds the site still wants cleared.
     *
     * The two are separated because only one of them did harm.
     *
     * **Stache: off.** Statamic's save() already updates the store and its
     * indexes, which is why a Control Panel save clears nothing. Clearing ran
     * `statamic:stache:clear` through Artisan inside the request, resetting the
     * in-memory stores mid-call; on a live multisite that left a structured
     * collection's tree empty, and CollectionStructure::validateTree() then
     * padded it with every entry at root — nested URLs flattened and, with
     * `root: true`, a random entry became the homepage (#53).
     *
     * **Static: on.** Tempting to drop as well, since StaticCaching\Invalidate
     * subscribes to the saved events — but its coverage has real holes, and a
     * stale page is a correctness bug users see. It does not subscribe to
     * CollectionSaved or TaxonomySaved at all; it listens for
     * LocalizedTermSaved, which a freshly created term does not dispatch; and
     * on a slug change it invalidates the new URL while the old one keeps
     * serving its cached page. Clearing the static cache is a rebuild, not
     * corruption, so it stays on until that coverage is better than a flush.
     *
     * @param  array<int, string>  $types
     *
     * @return array<string, string>
     */
    protected function clearCachesAfterWrite(array $types = ['stache', 'static']): array
    {
        $wanted = array_values(array_filter($types, fn (string $type): bool => match ($type) {
            'stache' => (bool) config('statamic.mcp.cache.clear_stache_after_write', false),
            'static' => (bool) config('statamic.mcp.cache.clear_static_after_write', true),
            default => true,
        }));

        if ($wanted === []) {
            return [];
        }

        return $this->clearStatamicCaches($wanted);
    }

    /**
     * Invalidate the static cache for one item, the way Statamic does.
     *
     * Statamic's StaticCaching\Invalidate subscribes to saved events for
     * entries, terms, globals, navs, forms, assets, blueprints and collection
     * *trees* — but not to CollectionSaved. So changing a collection's template
     * or layout invalidates nothing, and with static caching on, its entry
     * pages keep serving the old output. The blanket clear used to paper over
     * that; removing it (#53) left the gap exposed.
     *
     * DefaultInvalidator already knows how to turn a Collection into URLs, so
     * this is Statamic's own targeted path — not a flush, and nothing to do
     * with the Stache.
     *
     * Best-effort: static caching may be off, or the binding absent.
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
     * Clear relevant Statamic caches.
     *
     * The explicit, caller-requested clear — the system router's cache_clear
     * action. Unconditional on purpose: someone asked for it.
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
            } catch (\Exception $e) {
                $results[$type] = 'failed';
                Log::warning("MCP cache clear failed for type '{$type}': {$e->getMessage()}");
            }
        }

        return $results;
    }
}
