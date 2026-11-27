<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Support;

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Contracts\CollectionTreeEditor as CollectionTreeEditorContract;
use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;
use Cboxdk\StatamicMcp\Mcp\Exceptions\ToolFailure;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Statamic\Contracts\Entries\Collection;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Routing\UrlBuilder;
use Statamic\Contracts\Structures\CollectionTreeRepository;
use Statamic\Facades\Blink;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Stache\Repositories\CollectionTreeRepository as StacheCollectionTreeRepository;
use Statamic\Structures\CollectionTree;
use Statamic\Structures\Tree;

/** Moves one existing branch. Never accepts replacement trees or deletes entries. */
class CollectionTreeEditor implements CollectionTreeEditorContract
{
    private function refuse(string $message, ErrorCode $code = ErrorCode::Validation): never
    {
        throw new ToolFailure($code, $message, $code === ErrorCode::Validation ? 'parent' : null);
    }

    private function context(Collection $collection, string $site, bool $write): CollectionTree
    {
        if (! $collection->hasStructure() || ! $collection->sites()->contains($site) || ! $siteObject = Site::get($site)) {
            $this->refuse('A structured collection and a valid collection site are required.');
        }
        $mode = $write ? 'write' : 'read';
        $policy = app(ResourcePolicy::class);
        foreach (['entries', 'structures'] as $domain) {
            if ($write && array_intersect(['parent', 'tree', 'children'], $policy->getDeniedFields($domain))) {
                $this->refuse('Tree changes are denied by resource field policy.', ErrorCode::Forbidden);
            }
            if (! $policy->canAccess($domain, $collection->handle(), $mode)) {
                $this->refuse("{$mode} access to this collection is denied by the {$domain} resource policy.", ErrorCode::Forbidden);
            }
        }
        if (McpContext::isWebRequest()) {
            $user = auth()->user();
            if (! $user || ! Gate::forUser($user)->allows('view', $collection) || ! Gate::forUser($user)->allows('view', $siteObject)) {
                $this->refuse('Permission denied: Cannot access this collection site.', ErrorCode::Forbidden);
            }
            if ($write && ! Gate::forUser($user)->allows('reorder', $collection)) {
                $this->refuse('Permission denied: Reordering this collection is required.', ErrorCode::Forbidden);
            }
            if ($token = request()->attributes->get('mcp_token')) {
                if (! $token instanceof McpTokenData) {
                    $this->refuse('Invalid MCP token context.', ErrorCode::Forbidden);
                }
                $scopes = $write ? [TokenScope::EntriesWrite, TokenScope::StructuresAdmin] : [TokenScope::EntriesRead, TokenScope::StructuresRead];
                foreach ($scopes as $scope) {
                    if (! app(TokenService::class)->hasScope($token, $scope)) {
                        $this->refuse("Token missing required scope: {$scope->value}", ErrorCode::ScopeMissing);
                    }
                }
            }
        }
        // These Blink keys also exist on the minimum supported Statamic 6.6.
        // Structure::flushCache() and Tree::flushCache() were added later.
        Blink::forget('collection-structure-tree-' . $collection->handle() . '-' . $site);
        Blink::forget('collection-structure-tree-entries::' . $collection->handle() . '::' . $site);
        Blink::forget('structure-' . $collection->handle() . '-' . $site . '-*');
        Blink::forget('collection-structure-flattened-pages-collection' . $collection->handle() . '-' . $site);
        $tree = $collection->structure()->in($site);
        if (! $tree instanceof CollectionTree) {
            $this->refuse('Collection tree not found.', ErrorCode::NotFound);
        }

        return $this->freshTree($tree);
    }

    private function freshTree(CollectionTree $source): CollectionTree
    {
        $tree = $source->structure()->makeTree($source->locale(), $source->getOriginal('tree', []));
        if (! $tree instanceof CollectionTree) {
            $this->refuse('Collection tree not found.', ErrorCode::NotFound);
        }

        // Rebuild pages without inheriting cached parents or URIs, while
        // retaining the original tree for Statamic's save events and diff.
        return $tree->tree($source->tree())->disableUriCache();
    }

    /** @return array<string, mixed> */
    public function read(Collection $collection, string $site): array
    {
        $tree = $this->context($collection, $site, false);

        $policy = app(ResourcePolicy::class);

        return $policy->filterFields('structures', $policy->filterFields('entries', [
            'site' => $site,
            'tree' => $tree->tree(),
        ]));
    }

    public function parent(EntryContract $entry): ?string
    {
        $parent = $entry->parent();

        return $parent && ! $parent->isRoot() ? $parent->reference() : null;
    }

    /** Validate before saving any entry fields, including on create. */
    public function validate(EntryContract $entry, mixed $parent): void
    {
        $this->plan($entry, $parent);
    }

    private function plan(EntryContract $entry, mixed $parent): CollectionTreeMove
    {
        if ($parent !== null && (! is_string($parent) || $parent === '')) {
            $this->refuse('Parent must be an entry id or null for the top level.');
        }
        if ($parent !== null && $parent === $entry->id()) {
            $this->refuse('An entry cannot be its own parent or be moved below its descendant.');
        }
        $collection = $entry->collection();
        $site = $entry->locale();
        // Before the tree is read for planning: a change in between can only
        // refuse this move, never be overwritten by it.
        $version = $this->storedTreeVersion($collection, $site);
        $tree = $this->context($collection, $site, true);
        if (McpContext::isWebRequest() && $entry->id() && Entry::find($entry->id()) && ! Gate::forUser(auth()->user())->allows('edit', $entry)) {
            $this->refuse('Permission denied: Cannot move this entry (author or site restriction).', ErrorCode::Forbidden);
        }
        $parentPage = $parent ? $tree->find($parent) : null;
        if ($parent !== null) {
            $target = Entry::find($parent);
            if (! $target || $target->collectionHandle() !== $collection->handle() || $target->locale() !== $site || ! $parentPage) {
                $this->refuse('Parent must exist in the same collection and site.');
            }
            if (McpContext::isWebRequest() && ! Gate::forUser(auth()->user())->allows('view', $target)) {
                $this->refuse('Permission denied: Cannot view the requested parent.', ErrorCode::Forbidden);
            }
            if ($parentPage->isRoot()) {
                $parent = null;
                $parentPage = null;
            }
        }
        $page = $entry->id() ? $tree->find($entry->id()) : null;
        if ($page?->isRoot() && $parent !== null) {
            $this->refuse('The collection root cannot be moved.');
        }
        $cursor = $parentPage;
        while ($cursor) {
            if ($cursor->reference() === $entry->id()) {
                $this->refuse('An entry cannot be its own parent or be moved below its descendant.');
            }
            $cursor = $cursor->parent();
        }
        $depth = $parentPage ? $parentPage->depth() + 1 : 1;
        $subtreeDepth = 0;
        if ($page) {
            foreach ($page->flattenedPages() as $descendant) {
                $subtreeDepth = max($subtreeDepth, $descendant->depth() - $page->depth());
                if (McpContext::isWebRequest() && ! Gate::forUser(auth()->user())->allows('edit', $descendant->entry())) {
                    $this->refuse('Permission denied: Cannot move every entry in this subtree.', ErrorCode::Forbidden);
                }
            }
        }
        if (($max = $collection->structure()->maxDepth()) && $depth + $subtreeDepth > $max) {
            $this->refuse('The requested parent exceeds the collection maximum depth.');
        }
        if ($entry->route()) {
            $isRoot = $page?->isRoot()
                || (! $entry->id() && $collection->structure()->expectsRoot() && $tree->tree() === []);
            $parentUri = $parentPage?->uri();
            if ($parentUri !== null && ! is_string($parentUri)) {
                $this->refuse('The requested parent has an invalid URI.');
            }
            $uri = app(UrlBuilder::class)->content($entry)->merge([
                'parent_uri' => str_replace(['.html', '.htm'], '', $parentUri ?? ''),
                'slug' => $isRoot ? '' : $entry->slug(),
                'depth' => $depth, 'is_root' => $isRoot,
            ])->build($entry->route());
            $existing = Entry::findByUri($uri, $site);
            if ($existing && $existing->id() !== $entry->id()) {
                $this->refuse('The requested parent would produce a duplicate entry URI.');
            }
        }
        $candidate = null;
        if ($page) {
            $candidate = $this->freshTree($tree);
            // A new root is already in its final place after the entry save.
            if (! $page->isRoot()) {
                $candidate->move($entry->id(), $parent);
            }
            $candidate = $this->freshTree($candidate);
            $seen = [];
            foreach ($candidate->flattenedPages() as $candidatePage) {
                if ($candidatePage->reference() === $entry->id()) {
                    $candidatePage->setEntry($entry);
                }
                if ($uri = $candidatePage->uri()) {
                    if (isset($seen[$uri])) {
                        $this->refuse('The requested move would produce duplicate entry URIs.');
                    }
                    $owner = Entry::findByUri($uri, $site);
                    if ($owner && $owner->collectionHandle() !== $collection->handle()) {
                        $this->refuse('The requested move would collide with another collection URI.');
                    }
                    $seen[$uri] = true;
                }
            }
        }

        return new CollectionTreeMove($tree, $candidate, $version, $parent);
    }

    /**
     * The stored tree's version, read past every in-memory cache.
     *
     * Read before the move is planned and again before it is saved; the
     * planned tree cannot serve as the first read, because Statamic adds the
     * entries the stored tree lacks to it in memory.
     *
     * The Stache keeps a tree in a file, so the file's hash is its version.
     * Another repository (the Eloquent driver keeps trees in a table) has no
     * file: its path() names one that does not exist, so hashing it gave null
     * on every read and a concurrent change was never noticed. There the
     * version is the stored tree's contents.
     */
    private function storedTreeVersion(Collection $collection, string $site): string
    {
        $repository = app(CollectionTreeRepository::class);
        if (get_class($repository) === StacheCollectionTreeRepository::class) {
            $path = $repository->find($collection->handle(), $site)?->path();

            return is_string($path) && is_file($path) ? (hash_file('sha256', $path) ?: 'unreadable') : 'absent';
        }
        Blink::flush();
        $stored = $repository->find($collection->handle(), $site);

        // fileData(), not tree(): tree() validates, dropping ids it cannot find.
        return $stored instanceof Tree ? hash('sha256', json_encode($stored->fileData()) ?: '') : 'absent';
    }

    public function move(EntryContract $entry, mixed $parent): ?string
    {
        $savedParent = null;
        Cache::lock('mcp-collection-tree:' . $entry->collectionHandle() . ':' . $entry->locale(), 60)->block(5, function () use ($entry, $parent, &$savedParent): void {
            $plan = $this->plan($entry, $parent);
            if (! $plan->candidate) {
                $this->refuse('The entry must be saved in the collection tree before it can be moved.');
            }
            if ($this->storedTreeVersion($entry->collection(), $entry->locale()) !== $plan->version) {
                $this->refuse('The tree changed during this operation. Read it again before retrying.', ErrorCode::VersionConflict);
            }
            if (! $plan->candidate->save()) {
                $this->refuse('The tree could not be saved.', ErrorCode::Internal);
            }
            Blink::flush();

            $actualParent = $this->parent($entry);
            if ($actualParent !== $plan->parent) {
                $this->refuse('The tree save did not apply the requested parent.', ErrorCode::OutcomeUnknown);
            }

            $savedParent = $actualParent;
        });

        return $savedParent;
    }
}
