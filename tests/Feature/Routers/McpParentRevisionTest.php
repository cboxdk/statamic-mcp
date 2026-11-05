<?php

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Contracts\CollectionTreeEditor;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class McpParentRevisionTest extends TestCase
{
    private function fixture(): object
    {
        $collection = $this->createMock(\Statamic\Entries\Collection::class);
        $collection->method('handle')->willReturn('pages');
        Collection::swap(new class($collection)
        {
            public function __construct(private $collection) {}

            public function find($handle)
            {
                return $handle === 'pages' ? $this->collection : null;
            }
        });
        $entry = $this->createMock(\Statamic\Entries\Entry::class);
        $entry->method('id')->willReturn('revision-probe');
        $entry->method('collectionHandle')->willReturn('pages');
        $entry->method('site')->willReturn(Site::get('default'));
        $entry->method('published')->willReturn(true);
        $entry->method('revisionsEnabled')->willReturn(true);
        $entry->expects($this->never())->method('save');
        $entry->expects($this->never())->method('store');
        Entry::swap(new class($entry)
        {
            public function __construct(private $entry) {}

            public function find($id)
            {
                return $id === 'revision-probe' ? $this->entry : null;
            }
        });

        return $entry;
    }

    private function update(array $data): array
    {
        return (new \ReflectionMethod(EntriesRouter::class, 'executeInternal'))->invoke(new EntriesRouter, [
            'action' => 'update', 'collection' => 'pages', 'id' => 'revision-probe', 'site' => 'default', 'data' => $data,
        ]);
    }

    public function test_published_parent_move_is_separate_from_working_copy_content(): void
    {
        $entry = $this->fixture();
        $editor = $this->createMock(CollectionTreeEditor::class);
        $editor->expects($this->once())->method('move')->with($entry, 'new-parent')->willReturn('new-parent');
        app()->instance(CollectionTreeEditor::class, $editor);
        $result = $this->update(['parent' => 'new-parent']);
        $this->assertTrue($result['updated']);
        $this->assertSame('new-parent', $result['entry']['parent']);
    }

    public function test_mixed_parent_and_content_write_is_explicitly_rejected_for_published_revisions(): void
    {
        $this->fixture();
        $editor = $this->createMock(CollectionTreeEditor::class);
        $editor->expects($this->never())->method('move');
        app()->instance(CollectionTreeEditor::class, $editor);
        $result = $this->update(['parent' => 'new-parent', 'title' => 'Must not be saved']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('separately from content edits', $result['errors'][0]);
    }
}
