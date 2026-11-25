<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Support;

use Statamic\Contracts\Assets\AssetRepository;
use Statamic\Contracts\Auth\UserRepository;
use Statamic\Contracts\Entries\Collection as CollectionContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Entries\EntryRepository;
use Statamic\Contracts\Taxonomies\TermRepository;
use Statamic\Events\AssetContainerDeleted;
use Statamic\Events\AssetContainerSaved;
use Statamic\Events\CollectionDeleted;
use Statamic\Events\CollectionSaved;
use Statamic\Events\CollectionTreeDeleted;
use Statamic\Events\CollectionTreeSaved;
use Statamic\Events\EntryDeleted;
use Statamic\Events\RoleDeleted;
use Statamic\Events\RoleSaved;
use Statamic\Events\TaxonomyDeleted;
use Statamic\Events\TaxonomySaved;
use Statamic\Events\UserGroupDeleted;
use Statamic\Events\UserGroupSaved;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection;
use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy;
use Statamic\Stache\Indexes\Index;
use Statamic\Stache\Stores\AggregateStore;
use Statamic\Stache\Stores\Store;
use Statamic\Stache\Stores\TaxonomyTermsStore;
use Statamic\Structures\CollectionTree;

/**
 * Brings the Stache indexes a write left stale up to date, instead of clearing
 * the whole Stache.
 *
 * Statamic updates the store of the item it saves. What it leaves behind are
 * indexes in *other* places that depend on the write: a deleted entry's rows in
 * a taxonomy's associations, the order of the siblings of a page that moved,
 * the URIs of a collection mounted on a deleted page, every index of a
 * collection whose route, mount, dating or taxonomies changed, and the role
 * and group membership of users when a group or role changes.
 *
 * Which writes leave what behind is not guessed here: StacheConsistencyTest
 * runs every write without a clear and compares every index of every store
 * with a rebuild from disk. A write it finds stale gets a repair below.
 *
 * Repairs are recorded from Statamic's own events while a tool call runs, so
 * they follow what was actually written rather than which router wrote it,
 * and run once the call is finished, like the clear they replace (#53).
 */
class StacheRepair
{
    /** Events whose writes leave something outside their own store stale. */
    public const EVENTS = [
        EntryDeleted::class,
        CollectionTreeSaved::class,
        CollectionTreeDeleted::class,
        CollectionSaved::class,
        CollectionDeleted::class,
        TaxonomySaved::class,
        TaxonomyDeleted::class,
        UserGroupSaved::class,
        UserGroupDeleted::class,
        RoleSaved::class,
        RoleDeleted::class,
        AssetContainerSaved::class,
        AssetContainerDeleted::class,
    ];

    private bool $recording = false;

    /** @var array<string, \Closure(): void> keyed so a repair runs once per call */
    private array $repairs = [];

    public function start(): void
    {
        $this->recording = true;
        $this->repairs = [];
    }

    /** Stop recording without repairing: the call asked for nothing. */
    public function stop(): void
    {
        $this->recording = false;
        $this->repairs = [];
    }

    /** Run the repairs this call's writes need, then stop recording. */
    public function apply(): void
    {
        $repairs = $this->repairs;
        $this->stop();

        if ($repairs === []) {
            return;
        }

        // Read what was written, not what this call memoized on the way.
        Blink::swap(new \Statamic\Support\Blink);

        foreach ($repairs as $repair) {
            $repair();
        }
    }

    public function record(object $event): void
    {
        if (! $this->recording) {
            return;
        }

        match (true) {
            $event instanceof EntryDeleted => $this->entryDeleted($event->entry),
            $event instanceof CollectionTreeSaved, $event instanceof CollectionTreeDeleted => $this->treeChanged($event->tree),
            $event instanceof CollectionSaved, $event instanceof CollectionDeleted => $this->collectionChanged($event->collection),
            $event instanceof TaxonomySaved, $event instanceof TaxonomyDeleted => $this->taxonomyChanged((string) $event->taxonomy->handle()),
            $event instanceof UserGroupSaved, $event instanceof UserGroupDeleted,
            $event instanceof RoleSaved, $event instanceof RoleDeleted => $this->membershipChanged(),
            $event instanceof AssetContainerSaved, $event instanceof AssetContainerDeleted => $this->containerChanged((string) $event->container->handle()),
            default => null,
        };
    }

    private function entryDeleted(mixed $entry): void
    {
        if (! $entry instanceof EntryContract) {
            return;
        }
        $id = (string) $entry->id();

        // Statamic forgets the entry in its own store, not in the associations
        // of the taxonomies it was tagged with: its terms keep counting it.
        if ($this->stacheBacks(TermRepository::class, \Statamic\Stache\Repositories\TermRepository::class)) {
            $this->repairs["associations:{$id}"] = function () use ($entry): void {
                foreach ($this->taxonomyHandles() as $taxonomy) {
                    $store = $this->store('terms', $taxonomy);
                    if ($store instanceof TaxonomyTermsStore) {
                        $store->sync($entry, []);
                    }
                }
            };
        }

        // Saving a page updates the URIs of a collection mounted on it;
        // deleting it does not, so that collection keeps the old prefix.
        $this->repairs["mounted:{$id}"] = function () use ($id): void {
            foreach (Collection::all() as $collection) {
                if ($collection instanceof CollectionContract && ($collection->fileData()['mount'] ?? null) === $id) {
                    $collection->updateEntryUris();
                }
            }
        };
    }

    /**
     * Statamic updates the order of the pages a tree change moved or added,
     * not of the siblings whose position shifted because of it.
     */
    private function treeChanged(mixed $tree): void
    {
        if (! $tree instanceof CollectionTree
            || ! $this->stacheBacks(EntryRepository::class, \Statamic\Stache\Repositories\EntryRepository::class)) {
            return;
        }
        $handle = (string) $tree->handle();
        $site = (string) $tree->locale();

        $this->repairs["order:{$handle}:{$site}"] = function () use ($handle, $site): void {
            $collection = Collection::findByHandle($handle);
            $store = $this->store('entries', $handle);
            if (! $collection?->hasStructure() || $store === null || ! $store->indexes()->has('order')) {
                return;
            }
            $tree = $collection->structure()?->in($site);
            if ($tree === null) {
                return;
            }

            $order = $store->index('order');
            $shifted = $tree->flattenedPages()->map->reference()->filter()->values()
                ->filter(fn (mixed $id, int $position): bool => $order->get($id) !== $position + 1)
                ->all();

            if ($shifted !== []) {
                $collection->updateEntryOrder($shifted);
            }
        };
    }

    /**
     * A collection's route, mount, dating, structure and taxonomies all shape
     * the indexes of its entries, and its taxonomies' associations carry its
     * handle. Rare writes, so those stores are rebuilt rather than patched.
     *
     * Every save counts: by the time CollectionSaved fires the collection no
     * longer reports itself dirty, which is also why Statamic's own URI update
     * on a route change does not run.
     */
    private function collectionChanged(CollectionContract $collection): void
    {
        $handle = (string) $collection->handle();

        if ($this->stacheBacks(EntryRepository::class, \Statamic\Stache\Repositories\EntryRepository::class)) {
            $this->repairs["entries:{$handle}"] = fn () => $this->store('entries', $handle)?->clear();
        }

        if ($this->stacheBacks(TermRepository::class, \Statamic\Stache\Repositories\TermRepository::class)) {
            // Taxonomies attached now, and those still holding rows for it:
            // a detached taxonomy keeps counting the collection's entries.
            $this->repairs["collection-terms:{$handle}"] = function () use ($handle): void {
                foreach ($this->taxonomyHandles() as $taxonomy) {
                    $store = $this->store('terms', $taxonomy);
                    if ($store === null) {
                        continue;
                    }
                    $attached = Collection::findByHandle($handle)?->taxonomies()->map->handle()->contains($taxonomy) ?? false;
                    $associated = $store->index('associations')->items()
                        ->contains(fn (mixed $row): bool => is_array($row) && ($row['collection'] ?? null) === $handle);
                    if ($attached || $associated) {
                        $store->clear();
                    }
                }
            };
        }
    }

    private function taxonomyChanged(string $handle): void
    {
        if ($this->stacheBacks(TermRepository::class, \Statamic\Stache\Repositories\TermRepository::class)) {
            $this->repairs["terms:{$handle}"] = fn () => $this->store('terms', $handle)?->clear();
        }
    }

    /**
     * Users are indexed by membership of every group and role. Cleared, not
     * updated: a deleted group's index must go, and the rest rebuild when a
     * query next asks for them.
     */
    private function membershipChanged(): void
    {
        if (! $this->stacheBacks(UserRepository::class, \Statamic\Stache\Repositories\UserRepository::class)) {
            return;
        }

        $this->repairs['users:membership'] = function (): void {
            $store = $this->store('users');
            if ($store === null) {
                return;
            }
            $store->resolveIndexes()
                ->filter(fn (mixed $index, mixed $name): bool => is_string($name) && (str_starts_with($name, 'groups/') || str_starts_with($name, 'roles/')))
                ->each(function (Index $index, string $name) use ($store): void {
                    $index->clear();
                    app('stache.indexes')->forget("{$store->key()}.{$name}");
                });
        };
    }

    /** A container's disk or folder decides which files its assets are. */
    private function containerChanged(string $handle): void
    {
        if ($this->stacheBacks(AssetRepository::class, \Statamic\Assets\AssetRepository::class)) {
            $this->repairs["assets:{$handle}"] = fn () => $this->store('assets', $handle)?->clear();
        }
    }

    /** A Stache store, or one child of an aggregate store such as entries or terms. */
    private function store(string $key, ?string $child = null): ?Store
    {
        $store = Stache::store($key);
        if ($child !== null) {
            $store = $store instanceof AggregateStore ? $store->store($child) : null;
        }

        return $store instanceof Store ? $store : null;
    }

    /** @return list<string> */
    private function taxonomyHandles(): array
    {
        return array_values(Taxonomy::handles()->filter(fn (mixed $handle): bool => is_string($handle))->all());
    }

    /** @param  class-string  $contract */
    private function stacheBacks(string $contract, string $stacheImplementation): bool
    {
        return app($contract) instanceof $stacheImplementation;
    }
}
