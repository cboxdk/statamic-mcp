<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Concerns;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

trait ClearsCaches
{
    /**
     * Clear caches after a write, if the site has asked for it.
     *
     * Off by default, and that is the correction rather than the compromise.
     * Statamic's own save() updates the Stache store and its indexes, and
     * StaticCaching\Invalidate invalidates static pages from the saved events
     * using the site's own rules — which is why saving in the Control Panel
     * never clears anything. Doing it here was redundant work at best.
     *
     * At worst it corrupted live sites (#53). `statamic:stache:clear` runs
     * through Artisan inside the request, so it resets the in-memory stores
     * halfway through a tool call. On a multisite with a structured collection
     * that left the tree repository returning nothing for the site;
     * CollectionStructure::validateTree() then padded the empty tree with every
     * entry at root in Stache order, so nested URLs flattened and — with
     * `root: true` — a random entry became the homepage. Twice in one day, both
     * times minutes after a batch of MCP writes.
     *
     * Sites with an unusual setup can restore the old behaviour with
     * STATAMIC_MCP_CLEAR_CACHE_AFTER_WRITE=true.
     *
     * @param  array<int, string>  $types
     *
     * @return array<string, string>
     */
    protected function clearCachesAfterWrite(array $types = ['stache', 'static']): array
    {
        if (! config('statamic.mcp.cache.clear_after_write', false)) {
            return [];
        }

        return $this->clearStatamicCaches($types);
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
