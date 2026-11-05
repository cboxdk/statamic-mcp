<?php

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Contracts\CollectionTreeEditor;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Entries\Collection;
use Statamic\Facades\Entry;
use Statamic\Fields\Blueprint;
use Statamic\Structures\CollectionStructure;
use Statamic\Structures\CollectionTree;

class McpParentBehaviorTest extends TestCase
{
    public function test_parent_update_calls_the_tree_writer_and_reports_the_saved_parent(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('handle')->willReturn('parent_probe');
        $collection->method('dated')->willReturn(false);
        $collection->method('revisionsEnabled')->willReturn(false);
        $structure = $this->createMock(CollectionStructure::class);
        $structure->method('validateTree')->willReturnArgument(0);
        $structure->method('expectsRoot')->willReturn(false);
        $tree = $this->getMockBuilder(CollectionTree::class)->onlyMethods(['structure'])->getMock();
        $tree->method('structure')->willReturn($structure);
        $tree->handle('parent_probe')->locale('default')->tree([
            ['entry' => 'old-parent', 'children' => [['entry' => 'child']]],
            ['entry' => 'new-parent'],
        ]);
        \Statamic\Facades\Collection::swap(new class($collection)
        {
            public function __construct(private $collection) {}

            public function find($handle)
            {
                return $handle === 'parent_probe' ? $this->collection : null;
            }
        });
        $before = $tree->tree();
        $blueprint = (new Blueprint)->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
            ]]]]],
        ]);
        $entry = $this->getMockBuilder(\Statamic\Entries\Entry::class)
            ->onlyMethods(['collectionHandle', 'collection', 'blueprint', 'save', 'url', 'lastModified', 'page'])
            ->getMock();
        $entry->method('collection')->willReturn($collection);
        $entry->method('collectionHandle')->willReturn('parent_probe');
        $entry->method('blueprint')->willReturn($blueprint);
        $entry->method('page')->willReturnCallback(fn () => $tree->find('child'));
        $entry->method('url')->willReturn('/old-parent/child');
        $entry->method('lastModified')->willReturn(null);
        $entry->id('child')->locale('default')->slug('child')->published(false)->data(['title' => 'Child']);
        $saved = null;
        $entry->expects($this->once())->method('save')->willReturnCallback(function () use ($entry, &$saved) {
            $saved = $entry->data()->all();

            return true;
        });
        Entry::swap(new class($entry)
        {
            public function __construct(private $entry) {}

            public function find($id)
            {
                return $id === 'child' ? $this->entry : null;
            }
        });
        $this->assertSame('old-parent', $entry->parent()->reference());
        $editor = $this->createMock(CollectionTreeEditor::class);
        $editor->expects($this->once())->method('validate')->with($entry, 'new-parent');
        $editor->expects($this->once())->method('move')->willReturnCallback(function ($entry, $parent) use ($tree) {
            $tree->move($entry->id(), $parent)->flushCache();

            return $parent;
        });
        $editor->method('parent')->willReturnCallback(fn ($entry) => $entry->parent()?->reference());
        app()->instance(CollectionTreeEditor::class, $editor);
        $router = new EntriesRouter;
        $invoke = fn ($method, $args) => (new \ReflectionMethod($router, $method))->invoke($router, $args);
        $args = ['collection' => 'parent_probe', 'id' => 'child', 'site' => 'default'];
        $response = $invoke('executeInternal', $args + ['action' => 'update', 'data' => ['parent' => 'new-parent']]);
        $this->assertTrue($response['updated'] ?? false, json_encode($response));
        $this->assertArrayNotHasKey('parent', $saved);
        $this->assertNotSame($before, $tree->tree());
        $this->assertSame('new-parent', $entry->parent()->reference());
        $read = $invoke('executeInternal', $args + ['action' => 'get']);
        $this->assertSame('new-parent', $read['entry']['parent']);
        $this->assertArrayNotHasKey('parent', $read['entry']['data']);
    }
}
