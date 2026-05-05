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

    /**
     * Regression for https://github.com/cboxdk/statamic-mcp/issues/27.
     *
     * The previous updateEntry() flow injected the entry's current
     * slug into the FieldsValidator payload, which made
     * UniqueEntryValue compare the slug against the entry being
     * updated and reject it as "already taken" — even when the caller
     * never passed a slug at all.
     */
    public function test_update_entry_without_slug_in_data_succeeds(): void
    {
        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("update-no-slug-{$this->testId}")
            ->data(['title' => 'Original Title']);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => [
                'title' => 'Updated Title',
            ],
        ]);

        $this->assertTrue(
            $result['success'],
            'updating an entry without changing the slug must succeed; got: '
            . json_encode($result['errors'] ?? []),
        );

        $reloaded = Entry::find($entry->id());
        $this->assertSame('Updated Title', $reloaded->get('title'));
        $this->assertSame("update-no-slug-{$this->testId}", $reloaded->slug());
    }

    /**
     * Resending the entry's *current* slug as part of `data` is
     * idempotent and must not trigger UniqueEntryValue against the
     * entry itself.
     */
    public function test_update_entry_with_unchanged_slug_succeeds(): void
    {
        $slug = "update-same-slug-{$this->testId}";
        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug($slug)
            ->data(['title' => 'Original']);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => [
                'title' => 'Updated',
                'slug' => $slug,
            ],
        ]);

        $this->assertTrue(
            $result['success'],
            'resending the existing slug must be idempotent; got: '
            . json_encode($result['errors'] ?? []),
        );

        $reloaded = Entry::find($entry->id());
        $this->assertSame($slug, $reloaded->slug());
        $this->assertSame('Updated', $reloaded->get('title'));
    }

    /**
     * Renaming an entry to a slug that does not collide with any
     * other entry in the collection succeeds.
     */
    public function test_update_entry_with_new_unique_slug_succeeds(): void
    {
        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("update-rename-from-{$this->testId}")
            ->data(['title' => 'Will Be Renamed']);
        $entry->save();

        $newSlug = "update-rename-to-{$this->testId}";
        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => [
                'slug' => $newSlug,
            ],
        ]);

        $this->assertTrue(
            $result['success'],
            'updating to a unique new slug must succeed; got: '
            . json_encode($result['errors'] ?? []),
        );

        $reloaded = Entry::find($entry->id());
        $this->assertSame($newSlug, $reloaded->slug());
    }

    /**
     * Renaming an entry to a slug that another entry already owns
     * must surface the validation error.
     */
    public function test_update_entry_to_existing_slug_returns_error(): void
    {
        $other = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("update-collision-{$this->testId}")
            ->data(['title' => 'Other']);
        $other->save();

        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("update-target-{$this->testId}")
            ->data(['title' => 'Target']);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => [
                'slug' => $other->slug(),
            ],
        ]);

        $this->assertFalse(
            $result['success'],
            'updating to a slug already owned by another entry must fail',
        );
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
