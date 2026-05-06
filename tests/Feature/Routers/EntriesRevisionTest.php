<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Statamic\Sites\Sites;

/**
 * Tests for revision-aware behavior in EntriesRouter.
 */
class EntriesRevisionTest extends TestCase
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

    /**
     * Enable revisions for the test collection.
     */
    private function enableRevisions(): void
    {
        config(['statamic.revisions.enabled' => true]);
        config(['statamic.editions.pro' => true]);

        /** @var \Statamic\Entries\Collection $collection */
        $collection = Collection::find($this->collectionHandle);
        $collection->revisionsEnabled(true)->save();

        Stache::refresh();
    }

    private function enableMultisite(): void
    {
        config(['statamic.system.multisite' => true]);

        app(Sites::class)->setSites([
            'default' => [
                'name' => 'Default',
                'url' => '/',
                'locale' => 'en_US',
            ],
            'fr' => [
                'name' => 'French',
                'url' => '/fr/',
                'locale' => 'fr_FR',
            ],
        ]);

        /** @var \Statamic\Entries\Collection $collection */
        $collection = Collection::find($this->collectionHandle);
        $collection->sites(['default', 'fr'])->save();

        Stache::refresh();
    }

    // ========================================================================
    // Backward Compatibility Tests (revisions disabled)
    // ========================================================================

    public function test_update_saves_directly_when_revisions_disabled(): void
    {
        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("direct-save-{$this->testId}")
            ->data(['title' => 'Original'])
            ->published(true);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => ['title' => 'Updated'],
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['updated']);
        $this->assertArrayNotHasKey('working_copy', $result['data']);

        $reloaded = Entry::find($entry->id());
        $this->assertSame('Updated', $reloaded->get('title'));
    }

    public function test_publish_sets_published_true_when_revisions_disabled(): void
    {
        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("publish-no-rev-{$this->testId}")
            ->data(['title' => 'Draft Entry'])
            ->published(false);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'publish',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['published']);

        $reloaded = Entry::find($entry->id());
        $this->assertTrue($reloaded->published());
    }

    public function test_unpublish_sets_published_false_when_revisions_disabled(): void
    {
        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("unpublish-no-rev-{$this->testId}")
            ->data(['title' => 'Published Entry'])
            ->published(true);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'unpublish',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['unpublished']);

        $reloaded = Entry::find($entry->id());
        $this->assertFalse($reloaded->published());
    }

    // ========================================================================
    // Revision-Aware Tests
    // ========================================================================

    public function test_update_creates_working_copy_when_revisions_enabled_and_published(): void
    {
        $this->enableRevisions();

        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("wc-update-{$this->testId}")
            ->data(['title' => 'Published Original'])
            ->published(true);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => ['title' => 'Updated via WC'],
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['updated']);
        $this->assertTrue($result['data']['working_copy']);
        $this->assertTrue($result['data']['revision_status']['has_working_copy']);

        // The published entry should still have the original title
        $reloaded = Entry::find($entry->id());
        $this->assertSame('Published Original', $reloaded->get('title'));
    }

    public function test_update_saves_directly_for_unpublished_entry_even_with_revisions_enabled(): void
    {
        $this->enableRevisions();

        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("unpub-update-{$this->testId}")
            ->data(['title' => 'Unpublished Original'])
            ->published(false);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => ['title' => 'Updated Directly'],
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['updated']);
        $this->assertArrayNotHasKey('working_copy', $result['data']);

        $reloaded = Entry::find($entry->id());
        $this->assertSame('Updated Directly', $reloaded->get('title'));
    }

    public function test_get_returns_published_data_by_default(): void
    {
        $this->enableRevisions();

        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("get-pub-{$this->testId}")
            ->data(['title' => 'Published Version'])
            ->published(true);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'get',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('Published Version', $result['data']['entry']['data']['title']);
        $this->assertArrayHasKey('revision_status', $result['data']);
        $this->assertTrue($result['data']['revision_status']['revisions_enabled']);
    }

    public function test_get_with_version_working_copy_returns_working_copy_data(): void
    {
        $this->enableRevisions();

        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("get-wc-{$this->testId}")
            ->data(['title' => 'Published Version'])
            ->published(true);
        $entry->save();

        // Create a working copy by updating
        $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => ['title' => 'Working Copy Version'],
        ]);

        $result = $this->router->execute([
            'action' => 'get',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'version' => 'working_copy',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('Working Copy Version', $result['data']['entry']['data']['title']);
    }

    public function test_get_with_version_working_copy_returns_working_copy_metadata(): void
    {
        $this->enableRevisions();

        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("get-wc-meta-{$this->testId}")
            ->data(['title' => 'Published Version'])
            ->published(true);
        $entry->save();

        $workingCopySlug = "working-copy-{$this->testId}";

        $update = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => [
                'title' => 'Working Copy Version',
                'slug' => $workingCopySlug,
            ],
        ]);

        $this->assertTrue($update['success']);

        $result = $this->router->execute([
            'action' => 'get',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'version' => 'working_copy',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('Working Copy Version', $result['data']['entry']['data']['title']);
        $this->assertSame($workingCopySlug, $result['data']['entry']['slug']);
    }

    public function test_get_with_version_working_copy_errors_when_no_working_copy(): void
    {
        $this->enableRevisions();

        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("get-wc-none-{$this->testId}")
            ->data(['title' => 'Published Only'])
            ->published(true);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'get',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'version' => 'working_copy',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No working copy exists', $result['errors'][0]);
    }

    public function test_get_with_version_latest_returns_working_copy_when_exists(): void
    {
        $this->enableRevisions();

        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("get-latest-wc-{$this->testId}")
            ->data(['title' => 'Published Version'])
            ->published(true);
        $entry->save();

        // Create a working copy
        $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => ['title' => 'Latest WC Version'],
        ]);

        $result = $this->router->execute([
            'action' => 'get',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'version' => 'latest',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('Latest WC Version', $result['data']['entry']['data']['title']);
    }

    public function test_get_with_version_latest_returns_published_when_no_working_copy(): void
    {
        $this->enableRevisions();

        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("get-latest-pub-{$this->testId}")
            ->data(['title' => 'Published Only Version'])
            ->published(true);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'get',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'version' => 'latest',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('Published Only Version', $result['data']['entry']['data']['title']);
    }

    public function test_get_includes_revision_status_when_revisions_enabled(): void
    {
        $this->enableRevisions();

        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("get-status-{$this->testId}")
            ->data(['title' => 'Status Test'])
            ->published(true);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'get',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('revision_status', $result['data']);
        $this->assertTrue($result['data']['revision_status']['revisions_enabled']);
        $this->assertFalse($result['data']['revision_status']['has_working_copy']);
    }

    public function test_create_with_revisions_enabled_uses_store(): void
    {
        $this->enableRevisions();

        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => ['title' => 'Revision Created Entry'],
            'revision_message' => 'Initial creation',
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['created']);
        $this->assertArrayHasKey('revision_status', $result['data']);

        // store() saves as unpublished
        $this->assertFalse($result['data']['entry']['published']);
    }

    // ========================================================================
    // Publish/Unpublish with Revisions
    // ========================================================================

    public function test_publish_promotes_working_copy_when_revisions_enabled(): void
    {
        $this->enableRevisions();

        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("publish-wc-{$this->testId}")
            ->data(['title' => 'Original Published'])
            ->published(true);
        $entry->save();

        // Create a working copy with updated data
        $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => ['title' => 'Will Be Published'],
        ]);

        // Now publish (promotes working copy)
        $result = $this->router->execute([
            'action' => 'publish',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'revision_message' => 'Publishing working copy',
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['published']);

        // The entry should now have the working copy data
        $reloaded = Entry::find($entry->id());
        $this->assertSame('Will Be Published', $reloaded->get('title'));
        $this->assertTrue($reloaded->published());
    }

    // ========================================================================
    // Revision CRUD Actions
    // ========================================================================

    public function test_list_revisions_returns_history(): void
    {
        $this->enableRevisions();

        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("list-rev-{$this->testId}")
            ->data(['title' => 'Rev History Test'])
            ->published(false);
        $entry->save();

        // store() creates an initial revision
        $entry->store(['message' => 'First version']);

        $result = $this->router->execute([
            'action' => 'list_revisions',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('revisions', $result['data']);
        $this->assertGreaterThanOrEqual(1, $result['data']['total']);
    }

    public function test_list_revisions_returns_error_when_revisions_disabled(): void
    {
        // Revisions NOT enabled (default)
        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("list-rev-disabled-{$this->testId}")
            ->data(['title' => 'No Revisions'])
            ->published(true);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'list_revisions',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Revisions are not enabled', $result['errors'][0]);
    }

    public function test_get_revision_returns_specific_snapshot(): void
    {
        $this->enableRevisions();

        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("get-rev-{$this->testId}")
            ->data(['title' => 'Snapshot Test'])
            ->published(false);
        $entry->save();

        // Create a revision
        $entry->store(['message' => 'Snapshot version']);

        // List to get the revision ID
        $listResult = $this->router->execute([
            'action' => 'list_revisions',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
        ]);

        $this->assertTrue($listResult['success']);
        $this->assertGreaterThanOrEqual(1, count($listResult['data']['revisions']));

        $revisionId = $listResult['data']['revisions'][0]['id'];

        // Get that specific revision
        $result = $this->router->execute([
            'action' => 'get_revision',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'revision_id' => $revisionId,
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('revision', $result['data']);
        $this->assertArrayHasKey('attributes', $result['data']['revision']);
    }

    public function test_get_revision_returns_error_for_invalid_id(): void
    {
        $this->enableRevisions();

        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("get-rev-invalid-{$this->testId}")
            ->data(['title' => 'Invalid Rev Test'])
            ->published(false);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'get_revision',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'revision_id' => '9999999999',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Revision not found', $result['errors'][0]);
    }

    public function test_restore_revision_creates_working_copy_for_published_entry(): void
    {
        $this->enableRevisions();

        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("restore-pub-{$this->testId}")
            ->data(['title' => 'Original Version'])
            ->published(false);
        $entry->save();

        // Create a revision (via store)
        $entry->store(['message' => 'Version to restore']);

        // Publish the entry directly to make it published
        $entry->published(true)->save();

        // List revisions to get an ID
        $listResult = $this->router->execute([
            'action' => 'list_revisions',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
        ]);

        $this->assertTrue($listResult['success']);
        $revisionId = $listResult['data']['revisions'][0]['id'];

        // Restore that revision
        $result = $this->router->execute([
            'action' => 'restore_revision',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'revision_id' => $revisionId,
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['restored_as_working_copy']);
    }

    public function test_restore_revision_updates_directly_for_unpublished_entry(): void
    {
        $this->enableRevisions();

        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("restore-unpub-{$this->testId}")
            ->data(['title' => 'First Version'])
            ->published(false);
        $entry->save();

        // Create a revision
        $entry->store(['message' => 'First version saved']);

        // Update entry data
        $entry->set('title', 'Second Version')->save();

        // List revisions to get the original
        $listResult = $this->router->execute([
            'action' => 'list_revisions',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
        ]);

        $this->assertTrue($listResult['success']);
        $revisionId = $listResult['data']['revisions'][0]['id'];

        // Restore
        $result = $this->router->execute([
            'action' => 'restore_revision',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'revision_id' => $revisionId,
        ]);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['restored_as_working_copy']);
    }

    public function test_publish_working_copy_succeeds(): void
    {
        $this->enableRevisions();

        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("pwc-success-{$this->testId}")
            ->data(['title' => 'Original'])
            ->published(true);
        $entry->save();

        // Create a working copy
        $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => ['title' => 'WC Data'],
        ]);

        // Publish the working copy
        $result = $this->router->execute([
            'action' => 'publish_working_copy',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'revision_message' => 'Publishing WC',
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['published']);

        // The published entry should now have the WC data
        $reloaded = Entry::find($entry->id());
        $this->assertSame('WC Data', $reloaded->get('title'));
    }

    public function test_publish_working_copy_errors_when_no_working_copy(): void
    {
        $this->enableRevisions();

        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("pwc-none-{$this->testId}")
            ->data(['title' => 'No WC'])
            ->published(true);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'publish_working_copy',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No working copy exists', $result['errors'][0]);
    }

    public function test_update_rejects_published_flag_when_revisions_enabled(): void
    {
        $this->enableRevisions();

        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("reject-published-{$this->testId}")
            ->data(['title' => 'Published Entry'])
            ->published(true);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => [
                'published' => false,
            ],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('published flag cannot be changed via update', $result['errors'][0]);

        $reloaded = Entry::find($entry->id());
        $this->assertNotNull($reloaded);
        $this->assertTrue($reloaded->published());
        $this->assertFalse($reloaded->hasWorkingCopy());
    }

    public function test_list_revisions_honours_site_when_root_id_is_provided(): void
    {
        $this->enableMultisite();
        $this->enableRevisions();

        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("multisite-root-{$this->testId}")
            ->data(['title' => 'Default Entry'])
            ->published(true);
        $entry->save();

        $frEntry = $entry->makeLocalization('fr');
        $frEntry->data(['title' => 'Entree Francaise']);
        $frEntry->save();
        $frEntry->makeRevision()->message('French revision')->save();

        $result = $this->router->execute([
            'action' => 'list_revisions',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'site' => 'fr',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['total']);
        $this->assertSame('French revision', $result['data']['revisions'][0]['message']);
    }

    // ========================================================================
    // Validation Tests
    // ========================================================================

    public function test_missing_id_for_list_revisions(): void
    {
        $result = $this->router->execute([
            'action' => 'list_revisions',
            'collection' => $this->collectionHandle,
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Entry ID is required for list_revisions action', $result['errors'][0]);
    }

    public function test_missing_id_for_get_revision(): void
    {
        $result = $this->router->execute([
            'action' => 'get_revision',
            'collection' => $this->collectionHandle,
            'revision_id' => '12345',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Entry ID is required for get_revision action', $result['errors'][0]);
    }

    public function test_missing_revision_id_for_get_revision(): void
    {
        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("missing-revid-{$this->testId}")
            ->data(['title' => 'Test'])
            ->published(true);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'get_revision',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Revision ID is required for get_revision action', $result['errors'][0]);
    }

    public function test_missing_revision_id_for_restore_revision(): void
    {
        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("missing-revid-restore-{$this->testId}")
            ->data(['title' => 'Test'])
            ->published(true);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'restore_revision',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Revision ID is required for restore_revision action', $result['errors'][0]);
    }

    public function test_missing_id_for_publish_working_copy(): void
    {
        $result = $this->router->execute([
            'action' => 'publish_working_copy',
            'collection' => $this->collectionHandle,
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Entry ID is required for publish_working_copy action', $result['errors'][0]);
    }

    // ========================================================================
    // Permission Mapping Tests
    // ========================================================================

    public function test_correct_permissions_for_list_revisions(): void
    {
        // This is tested indirectly through the getRequiredPermissions method
        // list_revisions should require view permissions
        $reflection = new \ReflectionMethod($this->router, 'getRequiredPermissions');
        $reflection->setAccessible(true);

        $permissions = $reflection->invoke($this->router, 'list_revisions', ['collection' => $this->collectionHandle]);
        $this->assertEquals(["view {$this->collectionHandle} entries"], $permissions);
    }

    public function test_correct_permissions_for_get_revision(): void
    {
        $reflection = new \ReflectionMethod($this->router, 'getRequiredPermissions');
        $reflection->setAccessible(true);

        $permissions = $reflection->invoke($this->router, 'get_revision', ['collection' => $this->collectionHandle]);
        $this->assertEquals(["view {$this->collectionHandle} entries"], $permissions);
    }

    public function test_correct_permissions_for_restore_revision(): void
    {
        $reflection = new \ReflectionMethod($this->router, 'getRequiredPermissions');
        $reflection->setAccessible(true);

        $permissions = $reflection->invoke($this->router, 'restore_revision', ['collection' => $this->collectionHandle]);
        $this->assertEquals(["publish {$this->collectionHandle} entries"], $permissions);
    }

    public function test_correct_permissions_for_publish_working_copy(): void
    {
        $reflection = new \ReflectionMethod($this->router, 'getRequiredPermissions');
        $reflection->setAccessible(true);

        $permissions = $reflection->invoke($this->router, 'publish_working_copy', ['collection' => $this->collectionHandle]);
        $this->assertEquals(["publish {$this->collectionHandle} entries"], $permissions);
    }

    // ========================================================================
    // Graceful Error Handling
    // ========================================================================

    public function test_revisions_graceful_when_pro_not_licensed(): void
    {
        // Revisions config enabled but Pro not licensed
        config(['statamic.revisions.enabled' => true]);
        config(['statamic.editions.pro' => false]);

        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("no-pro-{$this->testId}")
            ->data(['title' => 'No Pro'])
            ->published(true);
        $entry->save();

        // Update should save directly (graceful fallback)
        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => ['title' => 'Updated Without Pro'],
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['updated']);
        $this->assertArrayNotHasKey('working_copy', $result['data']);

        $reloaded = Entry::find($entry->id());
        $this->assertSame('Updated Without Pro', $reloaded->get('title'));
    }
}
