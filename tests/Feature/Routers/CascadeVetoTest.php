<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Statamic\Events\CollectionDeleting;
use Statamic\Events\EntryDeleting;
use Statamic\Events\TaxonomyDeleting;
use Statamic\Events\TermDeleting;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

class CascadeVetoTest extends TestCase
{
    public function test_parent_veto_preserves_blueprints(): void
    {
        foreach (['collection', 'taxonomy'] as $type) {
            $handle = 'veto-' . bin2hex(random_bytes(4));
            $namespace = $type === 'collection' ? 'collections.' . $handle : 'taxonomies.' . $handle;
            $parent = $type === 'collection' ? Collection::make($handle) : Taxonomy::make($handle);
            $parent->save();
            Blueprint::make('default')->setNamespace($namespace)->setContents(['fields' => []])->save();
            Event::listen($type === 'collection' ? CollectionDeleting::class : TaxonomyDeleting::class, fn () => false);
            $result = (new StructuresRouter)->execute(['action' => 'delete', 'resource_type' => $type, 'handle' => $handle]);
            $this->assertFalse($result['success']);
            $this->assertNotNull(Blueprint::find($namespace . '.default'));
            $this->assertTrue($result['meta']['retry_requires_reconciliation']);
        }
    }

    public function test_forced_collection_delete_cannot_cascade_into_entries(): void
    {
        $handle = 'cascade-' . bin2hex(random_bytes(4));
        Collection::make($handle)->save();
        Blueprint::make('article')->setNamespace('collections.' . $handle)->setContents(['fields' => []])->save();
        $entry = Entry::make()->collection($handle)->slug('protected')->data(['title' => 'Keep']);
        $entry->save();
        Event::listen(EntryDeleting::class, fn () => false);

        $result = (new StructuresRouter)->execute(['action' => 'delete', 'resource_type' => 'collection', 'handle' => $handle, 'force' => true]);

        $this->assertFalse($result['success'], json_encode($result));
        $this->assertNotNull(Entry::find($entry->id()));
        $this->assertNotNull(Collection::find($handle));
        $this->assertNotNull(Blueprint::find('collections.' . $handle . '.article'));
        $this->assertStringContainsString('does not support forced cascading deletion', implode(' ', $result['errors']));
    }

    public function test_forced_taxonomy_delete_cannot_cascade_into_terms(): void
    {
        $handle = 'cascade-' . bin2hex(random_bytes(4));
        Taxonomy::make($handle)->save();
        Blueprint::make('topic')->setNamespace('taxonomies.' . $handle)->setContents(['fields' => []])->save();
        $term = Term::make()->taxonomy($handle)->slug('protected')->data(['title' => 'Keep']);
        $term->save();
        Event::listen(TermDeleting::class, fn () => false);

        $result = (new StructuresRouter)->execute(['action' => 'delete', 'resource_type' => 'taxonomy', 'handle' => $handle, 'force' => true]);

        $this->assertFalse($result['success'], json_encode($result));
        $this->assertNotNull(Term::find($term->id()));
        $this->assertNotNull(Taxonomy::find($handle));
        $this->assertNotNull(Blueprint::find('taxonomies.' . $handle . '.topic'));
        $this->assertStringContainsString('does not support forced cascading deletion', implode(' ', $result['errors']));
    }
}
