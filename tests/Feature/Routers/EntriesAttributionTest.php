<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Facades\Config;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Statamic\Facades\User;

/**
 * Every write names the user behind the token, the way the Control Panel
 * names the editor: on the revision, on the working copy and in `updated_by`.
 *
 * Statamic's Revisable methods take the user as an option and never fall back
 * to the authenticated one, and TracksLastModified removes `updated_by` when
 * handed null — so before this the revision history of an MCP save showed no
 * author, and a publish wiped the last editor off the entry.
 */
class EntriesAttributionTest extends TestCase
{
    private EntriesRouter $router;

    private string $collectionHandle;

    private UserContract $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new EntriesRouter;
        $this->collectionHandle = 'posts-' . bin2hex(random_bytes(4));

        Collection::make($this->collectionHandle)
            ->title('Posts')
            ->routes("/{$this->collectionHandle}/{slug}")
            ->save();

        Config::set('statamic.system.track_last_update', true);
        Config::set('statamic.mcp.web.enabled', true);
        Config::set('statamic.mcp.confirmation.enabled', false);

        $this->user = User::make()->id('editor-1')->email('editor@example.com');
        $this->user->makeSuper();
        $this->user->save();

        Stache::refresh();
    }

    protected function tearDown(): void
    {
        request()->headers->remove('X-MCP-Remote');

        parent::tearDown();
    }

    private function actingOverTheWeb(): void
    {
        request()->headers->set('X-MCP-Remote', 'true');
        $this->actingAs($this->user);
    }

    private function enableRevisions(): void
    {
        Config::set('statamic.revisions.enabled', true);
        Config::set('statamic.editions.pro', true);

        /** @var \Statamic\Entries\Collection $collection */
        $collection = Collection::find($this->collectionHandle);
        $collection->revisionsEnabled(true)->save();

        Stache::refresh();
    }

    private function createEntry(bool $published = true): EntryContract
    {
        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug('hello')
            ->published($published)
            ->data(['title' => 'Hello']);
        $entry->save();

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function act(array $arguments): array
    {
        return $this->router->execute(array_merge(['collection' => $this->collectionHandle], $arguments));
    }

    public function test_create_names_the_user_on_the_initial_revision_and_the_entry(): void
    {
        $this->enableRevisions();
        $this->actingOverTheWeb();

        $result = $this->act(['action' => 'create', 'data' => ['title' => 'Fresh', 'slug' => 'fresh']]);

        $this->assertTrue($result['success'], json_encode($result));

        /** @var \Statamic\Entries\Entry $entry */
        $entry = Entry::find($result['data']['entry']['id']);

        $this->assertSame('editor-1', $entry->revisions()->first()->user()?->id());
        $this->assertSame('editor-1', $entry->get('updated_by'));
    }

    public function test_update_of_a_published_entry_names_the_user_on_the_working_copy(): void
    {
        $this->enableRevisions();
        $entry = $this->createEntry();
        $this->actingOverTheWeb();

        $result = $this->act(['action' => 'update', 'id' => $entry->id(), 'data' => ['title' => 'Changed']]);

        $this->assertTrue($result['success'], json_encode($result));

        /** @var \Statamic\Entries\Entry $fresh */
        $fresh = Entry::find($entry->id());

        $this->assertTrue($fresh->hasWorkingCopy());
        $this->assertSame('editor-1', $fresh->workingCopy()->user()?->id());
    }

    public function test_publish_and_unpublish_name_the_user_on_the_revision_and_keep_the_last_editor(): void
    {
        $this->enableRevisions();
        $entry = $this->createEntry(published: false);
        $this->actingOverTheWeb();

        $result = $this->act(['action' => 'publish', 'id' => $entry->id()]);
        $this->assertTrue($result['success'], json_encode($result));

        /** @var \Statamic\Entries\Entry $fresh */
        $fresh = Entry::find($entry->id());
        $this->assertSame('editor-1', $fresh->revisions()->last()->user()?->id());
        $this->assertSame('publish', $fresh->revisions()->last()->action());
        $this->assertSame('editor-1', $fresh->get('updated_by'));

        $result = $this->act(['action' => 'unpublish', 'id' => $entry->id()]);
        $this->assertTrue($result['success'], json_encode($result));

        /** @var \Statamic\Entries\Entry $fresh */
        $fresh = Entry::find($entry->id());
        $this->assertSame('editor-1', $fresh->revisions()->last()->user()?->id());
        $this->assertSame('unpublish', $fresh->revisions()->last()->action());
        $this->assertSame('editor-1', $fresh->get('updated_by'));
    }

    public function test_publish_working_copy_and_restore_revision_name_the_user(): void
    {
        $this->enableRevisions();
        $entry = $this->createEntry();
        $this->actingOverTheWeb();

        $this->act(['action' => 'update', 'id' => $entry->id(), 'data' => ['title' => 'Draft']]);
        $result = $this->act(['action' => 'publish_working_copy', 'id' => $entry->id()]);
        $this->assertTrue($result['success'], json_encode($result));

        /** @var \Statamic\Entries\Entry $fresh */
        $fresh = Entry::find($entry->id());
        $published = $fresh->revisions()->last();
        $this->assertSame('publish', $published->action());
        $this->assertSame('editor-1', $published->user()?->id());

        $result = $this->act(['action' => 'restore_revision', 'id' => $entry->id(), 'revision_id' => (string) $published->date()->timestamp]);
        $this->assertTrue($result['success'], json_encode($result));

        /** @var \Statamic\Entries\Entry $fresh */
        $fresh = Entry::find($entry->id());
        $this->assertTrue($fresh->hasWorkingCopy());
        $this->assertSame('editor-1', $fresh->workingCopy()->user()?->id());
    }

    public function test_a_plain_save_without_revisions_stamps_updated_by(): void
    {
        $entry = $this->createEntry();
        $this->assertNull($entry->get('updated_by'));
        $this->actingOverTheWeb();

        $result = $this->act(['action' => 'update', 'id' => $entry->id(), 'data' => ['title' => 'Changed']]);
        $this->assertTrue($result['success'], json_encode($result));

        /** @var \Statamic\Entries\Entry $fresh */
        $fresh = Entry::find($entry->id());
        $this->assertSame('editor-1', $fresh->get('updated_by'));
        $this->assertNotNull($fresh->get('updated_at'));
    }

    public function test_the_local_server_leaves_the_last_editor_alone(): void
    {
        // CLI: no user is acting, so the stamp is neither set nor removed.
        $entry = $this->createEntry();
        $entry->set('updated_by', 'someone-else')->save();

        $result = $this->act(['action' => 'update', 'id' => $entry->id(), 'data' => ['title' => 'Changed']]);
        $this->assertTrue($result['success'], json_encode($result));

        /** @var \Statamic\Entries\Entry $fresh */
        $fresh = Entry::find($entry->id());
        $this->assertSame('someone-else', $fresh->get('updated_by'));
    }
}
