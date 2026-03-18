<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Concerns;

use Statamic\Entries\Collection;
use Statamic\Entries\Entry;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\GlobalSet as GlobalSetFacade;
use Statamic\Facades\Taxonomy as TaxonomyFacade;
use Statamic\Fields\Blueprint;
use Statamic\Globals\GlobalSet;
use Statamic\Taxonomies\Taxonomy;

/**
 * Shared helpers for creating Statamic content artifacts in tests.
 */
trait CreatesTestContent
{
    protected function createTestCollection(string $handle = 'blog'): Collection
    {
        $collection = CollectionFacade::make($handle)
            ->title(ucfirst($handle));
        $collection->save();

        return $collection;
    }

    protected function createTestBlueprint(string $collection = 'blog'): Blueprint
    {
        $blueprint = BlueprintFacade::makeFromFields([
            'title' => [
                'type' => 'text',
                'display' => 'Title',
            ],
            'content' => [
                'type' => 'markdown',
                'display' => 'Content',
            ],
            'tags' => [
                'type' => 'terms',
                'display' => 'Tags',
                'taxonomies' => ['tags'],
            ],
        ]);

        $blueprint->setHandle($collection);
        $blueprint->setNamespace("collections.{$collection}");
        $blueprint->save();

        return $blueprint;
    }

    protected function createTestTaxonomy(string $handle = 'tags'): Taxonomy
    {
        $taxonomy = TaxonomyFacade::make($handle)
            ->title(ucfirst($handle));
        $taxonomy->save();

        return $taxonomy;
    }

    protected function createTestGlobalSet(string $handle = 'settings'): GlobalSet
    {
        $globalSet = GlobalSetFacade::make($handle)
            ->title(ucfirst($handle));
        $globalSet->save();

        return $globalSet;
    }

    /**
     * Create and save a test entry in the given collection.
     *
     * @param  array<string, mixed>  $data
     */
    protected function createTestEntry(string $collection, array $data): Entry
    {
        $slug = $data['slug'] ?? str($data['title'] ?? 'untitled')->slug()->toString();
        $id = $data['id'] ?? bin2hex(random_bytes(8));

        $entry = EntryFacade::make()
            ->id($id)
            ->collection($collection)
            ->slug($slug)
            ->data($data);

        $entry->save();

        return $entry;
    }
}
