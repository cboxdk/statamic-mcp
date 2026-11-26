<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Support;

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
use Cboxdk\StatamicMcp\Authorization\McpAuthorizer;
use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;
use Cboxdk\StatamicMcp\Mcp\Exceptions\ToolFailure;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\AssetsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\TermsRouter;
use Cboxdk\StatamicMcp\Support\McpContext;
use Illuminate\Support\Facades\Gate;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Search\Result;
use Statamic\Contracts\Taxonomies\Term;
use Statamic\Facades\Search;
use Statamic\Facades\Site;
use Statamic\Search\Index;

/** Search hits are candidates, never authorization or a source of stored values. */
final class ContentSearch
{
    /** @return list<Index> */
    public function indexes(string $handle, ?string $site): array
    {
        $indexes = Search::indexes()->whereInstanceOf(Index::class)
            ->filter(fn (Index $index): bool => $index->handle() === $handle && ($site === null || $index->locale() === null || $index->locale() === $site))
            ->values()->all();
        if ($indexes === [] || ($site !== null && ! Site::get($site) instanceof \Statamic\Sites\Site)) {
            throw new ToolFailure(ErrorCode::NotFound, 'Search index or site not found.', 'handle');
        }

        return array_values($indexes);
    }

    /** @return list<array<string, mixed>> */
    public function search(string $handle, ?string $site, string $query): array
    {
        $items = [];
        foreach ($this->indexes($handle, $site) as $index) {
            // Hosted drivers may return only one provider page without a total
            // or continuation. Never present that subset as the complete search.
            if (! $index instanceof \Statamic\Search\Comb\Index) {
                throw new ToolFailure(ErrorCode::Validation, 'MCP search currently supports the native local index only. Hosted index pagination is not available.', 'handle');
            }
            // A read must never trigger ensureExists() and rebuild an index.
            if (! $index->exists()) {
                throw new ToolFailure(ErrorCode::NotFound, 'Search index is not built. A system administrator can run reindex.', 'handle');
            }
            foreach ($index->search($query)->get() as $hit) {
                if (! $hit instanceof Result) {
                    continue;
                }
                $record = $hit->getSearchable();
                if ($record instanceof Entry) {
                    $router = new EntriesRouter;
                    $args = ['collection' => $record->collectionHandle(), 'id' => $record->id(), 'site' => $record->locale()];
                    $type = 'entry';
                } elseif ($record instanceof Term) {
                    $router = new TermsRouter;
                    $args = ['taxonomy' => $record->taxonomyHandle(), 'id' => $record->id(), 'site' => $record->locale()];
                    $type = 'term';
                } elseif ($record instanceof Asset) {
                    $router = new AssetsRouter;
                    $args = ['resource_type' => 'asset', 'container' => $record->containerHandle(), 'path' => $record->path()];
                    $type = 'asset';
                } else {
                    // Custom providers and CP users/addons have no content read contract.
                    continue;
                }
                $domain = $router->domain();
                if (! config("statamic.mcp.tools.{$domain}.enabled", true)
                    || app(ResourcePolicy::class)->getDeniedFields($domain) !== []
                    || app(McpAuthorizer::class)->denial($router->describeCall('get', $args)) !== null) {
                    // Even a hit's presence can reveal a protected field's value.
                    // Shared indexes cannot remove those fields per caller.
                    continue;
                }
                if ($site !== null && isset($args['site']) && $args['site'] !== $site) {
                    continue;
                }
                if (! McpContext::isCli() && (Gate::denies('view', $record)
                    || (isset($args['site']) && Gate::denies('view', Site::get($args['site']))))) {
                    continue;
                }
                $items[$hit->getReference()] = ['resource_type' => $type, ...$args,
                    'title' => $hit->getCpTitle()];
            }
        }
        // Stable paging, without exposing global scores, snippets or raw index data.
        ksort($items);

        return array_values($items);
    }
}
