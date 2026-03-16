<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;

/**
 * Edge case and validation tests for EntriesRouter.
 * Core CRUD operations are tested in ContentRouterTest.
 */
class EntriesRouterTest extends TestCase
{
    private EntriesRouter $router;

    private string $testId;

    private string $collectionHandle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new EntriesRouter;
        $this->testId = bin2hex(random_bytes(8));

        config(['filesystems.disks.assets' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/assets'),
        ]]);

        Storage::fake('assets');

        $this->collectionHandle = "posts-{$this->testId}";
        Collection::make($this->collectionHandle)
            ->title('Posts')
            ->routes("/posts-{$this->testId}/{slug}")
            ->save();

        Stache::refresh();
    }

    public function test_nonexistent_collection_returns_error(): void
    {
        $result = $this->router->execute([
            'action' => 'list',
            'collection' => 'nonexistent-collection',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Collection not found: nonexistent-collection', $result['errors'][0]);
    }

    public function test_list_entries_with_pagination(): void
    {
        // Create 5 entries
        for ($i = 1; $i <= 5; $i++) {
            Entry::make()
                ->id("paginated-{$i}-{$this->testId}")
                ->collection($this->collectionHandle)
                ->slug("entry-{$i}-{$this->testId}")
                ->data(['title' => "Entry {$i}"])
                ->save();
        }

        $result = $this->router->execute([
            'action' => 'list',
            'collection' => $this->collectionHandle,
            'limit' => 2,
            'offset' => 0,
            'include_unpublished' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['data']['entries']);
        $this->assertEquals(5, $result['data']['pagination']['total']);
        $this->assertTrue($result['data']['pagination']['has_more']);
    }

    public function test_list_entries_with_offset(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            Entry::make()
                ->id("offset-{$i}-{$this->testId}")
                ->collection($this->collectionHandle)
                ->slug("offset-entry-{$i}-{$this->testId}")
                ->data(['title' => "Offset Entry {$i}"])
                ->save();
        }

        $result = $this->router->execute([
            'action' => 'list',
            'collection' => $this->collectionHandle,
            'limit' => 2,
            'offset' => 3,
            'include_unpublished' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['data']['entries']);
        $this->assertFalse($result['data']['pagination']['has_more']);
    }

    public function test_list_entries_excludes_unpublished_by_default(): void
    {
        Entry::make()
            ->id("pub-{$this->testId}")
            ->collection($this->collectionHandle)
            ->slug("published-{$this->testId}")
            ->data(['title' => 'Published'])
            ->published(true)
            ->save();

        Entry::make()
            ->id("draft-{$this->testId}")
            ->collection($this->collectionHandle)
            ->slug("draft-{$this->testId}")
            ->data(['title' => 'Draft'])
            ->published(false)
            ->save();

        // Default: no include_unpublished
        $result = $this->router->execute([
            'action' => 'list',
            'collection' => $this->collectionHandle,
        ]);

        $this->assertTrue($result['success']);
        $titles = collect($result['data']['entries'])->pluck('title')->toArray();
        $this->assertContains('Published', $titles);
        $this->assertNotContains('Draft', $titles);
    }

    public function test_list_entries_includes_unpublished_when_requested(): void
    {
        Entry::make()
            ->id("pub2-{$this->testId}")
            ->collection($this->collectionHandle)
            ->slug("published2-{$this->testId}")
            ->data(['title' => 'Published2'])
            ->published(true)
            ->save();

        Entry::make()
            ->id("draft2-{$this->testId}")
            ->collection($this->collectionHandle)
            ->slug("draft2-{$this->testId}")
            ->data(['title' => 'Draft2'])
            ->published(false)
            ->save();

        $result = $this->router->execute([
            'action' => 'list',
            'collection' => $this->collectionHandle,
            'include_unpublished' => true,
        ]);

        $this->assertTrue($result['success']);
        $titles = collect($result['data']['entries'])->pluck('title')->toArray();
        $this->assertContains('Published2', $titles);
        $this->assertContains('Draft2', $titles);
    }

    public function test_create_entry_without_slug_generates_from_title(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => [
                'title' => 'Auto Slug Generation Test',
            ],
        ]);

        if (! $result['success']) {
            dump('Create entry failed:', $result);
        }

        $this->assertTrue($result['success']);
        $this->assertEquals('auto-slug-generation-test', $result['data']['entry']['slug']);
    }

    public function test_update_nonexistent_entry_returns_error(): void
    {
        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => 'nonexistent-id',
            'data' => ['title' => 'Updated'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Entry not found: nonexistent-id', $result['errors'][0]);
    }

    public function test_publish_nonexistent_entry_returns_error(): void
    {
        $result = $this->router->execute([
            'action' => 'publish',
            'collection' => $this->collectionHandle,
            'id' => 'nonexistent-id',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Entry not found: nonexistent-id', $result['errors'][0]);
    }

    public function test_unpublish_nonexistent_entry_returns_error(): void
    {
        $result = $this->router->execute([
            'action' => 'unpublish',
            'collection' => $this->collectionHandle,
            'id' => 'nonexistent-id',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Entry not found: nonexistent-id', $result['errors'][0]);
    }

    public function test_delete_nonexistent_entry_returns_error(): void
    {
        $result = $this->router->execute([
            'action' => 'delete',
            'collection' => $this->collectionHandle,
            'id' => 'nonexistent-id',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Entry not found: nonexistent-id', $result['errors'][0]);
    }

    public function test_missing_id_for_update(): void
    {
        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'data' => ['title' => 'Test'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Entry ID is required for update action', $result['errors'][0]);
    }

    public function test_missing_id_for_delete(): void
    {
        $result = $this->router->execute([
            'action' => 'delete',
            'collection' => $this->collectionHandle,
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Entry ID is required for delete action', $result['errors'][0]);
    }

    public function test_missing_id_for_publish(): void
    {
        $result = $this->router->execute([
            'action' => 'publish',
            'collection' => $this->collectionHandle,
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Entry ID is required for publish action', $result['errors'][0]);
    }

    public function test_missing_id_for_unpublish(): void
    {
        $result = $this->router->execute([
            'action' => 'unpublish',
            'collection' => $this->collectionHandle,
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Entry ID is required for unpublish action', $result['errors'][0]);
    }
}
