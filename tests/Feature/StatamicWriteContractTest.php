<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature;

use Cboxdk\StatamicMcp\Contracts\CollectionTreeEditor;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\GlobalsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\UsersRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Statamic\Events\BlueprintDeleting;
use Statamic\Events\BlueprintSaving;
use Statamic\Events\EntryDeleting;
use Statamic\Events\EntrySaving;
use Statamic\Events\UserDeleting;
use Statamic\Events\UserSaving;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\User;

class StatamicWriteContractTest extends TestCase
{
    public function test_user_save_veto_does_not_report_an_update_as_success(): void
    {
        $user = User::make()->email('veto-' . bin2hex(random_bytes(4)) . '@example.test');
        $user->save();
        Event::listen(UserSaving::class, fn () => false);
        $result = (new UsersRouter)->execute(['resource_type' => 'user', 'action' => 'update', 'id' => $user->id(), 'data' => ['name' => 'Refused']]);
        $this->assertFalse($result['success'], json_encode($result));
        $this->assertStringContainsString('Statamic refused the write', implode(' ', $result['errors']));
        $this->assertTrue($result['meta']['retry_requires_reconciliation']);
    }

    public function test_user_delete_veto_preserves_account(): void
    {
        $user = User::make()->email('veto-' . bin2hex(random_bytes(4)) . '@example.test');
        $user->save();
        Event::listen(UserDeleting::class, fn () => false);
        $result = (new UsersRouter)->execute(['resource_type' => 'user', 'action' => 'delete', 'id' => $user->id()]);
        $this->assertFalse($result['success'], json_encode($result));
        $this->assertNotNull(User::find($user->id()));
    }

    public function test_globals_cannot_cross_the_users_site_boundary(): void
    {
        config(['statamic.editions.pro' => true, 'statamic.system.multisite' => true, 'statamic.mcp.security.force_web_mode' => true]);
        Site::setSites(['en' => ['name' => 'English', 'locale' => 'en_US', 'url' => '/'], 'de' => ['name' => 'Deutsch', 'locale' => 'de_DE', 'url' => '/de/']]);
        $handle = 'contract-' . bin2hex(random_bytes(4));
        $set = GlobalSet::make($handle)->title('Settings')->sites(['en', 'de']);
        $set->save();
        foreach (['en', 'de'] as $site) {
            $set->makeLocalization($site)->data(['title' => $site])->save();
        }
        Blueprint::make($handle)->setNamespace('globals')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
        ]])->save();
        Role::make()->handle($handle)->permissions(['access cp', 'access en site', "edit {$handle} globals"])->save();
        $user = User::make()->email($handle . '@example.test')->assignRole($handle);
        $user->save();
        $this->actingAs($user);

        foreach (['get', 'update'] as $action) {
            $result = (new GlobalsRouter)->execute(['action' => $action, 'handle' => $handle, 'site' => 'de', ...($action === 'update' ? ['data' => ['title' => 'Forbidden']] : [])]);
            $this->assertFalse($result['success'], json_encode($result));
            $this->assertStringContainsString('Permission denied', implode(' ', $result['errors']));
        }
        $this->assertSame('de', GlobalSet::find($handle)->in('de')->get('title'));
        $allowed = (new GlobalsRouter)->execute(['action' => 'get', 'handle' => $handle, 'site' => 'en']);
        $this->assertTrue($allowed['success'], json_encode($allowed));
    }

    public function test_statamic_save_veto_is_not_reported_as_success(): void
    {
        $handle = 'contract-' . bin2hex(random_bytes(4));
        Collection::make($handle)->save();
        $entry = Entry::make()->collection($handle)->slug('original')->published(false)->data(['title' => 'Before']);
        $entry->save();
        Event::listen(EntrySaving::class, fn () => false);
        $result = (new EntriesRouter)->execute(['action' => 'update', 'collection' => $handle, 'id' => $entry->id(), 'data' => ['title' => 'After']]);
        $this->assertFalse($result['success'], json_encode($result));
        $this->assertSame('Before', Entry::find($entry->id())->get('title'));
    }

    public function test_statamic_delete_veto_is_not_reported_as_success(): void
    {
        $handle = 'contract-' . bin2hex(random_bytes(4));
        Collection::make($handle)->save();
        $entry = Entry::make()->collection($handle)->slug('original')->data(['title' => 'Before']);
        $entry->save();
        Event::listen(EntryDeleting::class, fn () => false);
        $result = (new EntriesRouter)->execute(['action' => 'delete', 'collection' => $handle, 'id' => $entry->id()]);
        $this->assertFalse($result['success'], json_encode($result));
        $this->assertNotNull(Entry::find($entry->id()));
    }

    public function test_unpublish_veto_preserves_the_published_entry(): void
    {
        config(['statamic.revisions.enabled' => false]);
        $handle = 'contract-' . bin2hex(random_bytes(4));
        Collection::make($handle)->save();
        $entry = Entry::make()->collection($handle)->slug('live')->published(true)->data(['title' => 'Live']);
        $entry->save();
        Event::listen(EntrySaving::class, fn () => false);
        $result = (new EntriesRouter)->execute(['action' => 'unpublish', 'collection' => $handle, 'id' => $entry->id()]);
        $this->assertFalse($result['success'], json_encode($result));
        $this->assertTrue(Entry::find($entry->id())->published());
    }

    public function test_revision_creation_does_not_hide_an_entry_save_veto(): void
    {
        config(['statamic.editions.pro' => true, 'statamic.revisions.enabled' => true]);
        $handle = 'contract-' . bin2hex(random_bytes(4));
        Collection::make($handle)->revisionsEnabled(true)->save();
        Event::listen(EntrySaving::class, fn () => false);
        $result = (new EntriesRouter)->execute(['action' => 'create', 'collection' => $handle, 'data' => ['title' => 'Refused']]);
        $this->assertFalse($result['success'], json_encode($result));
        $this->assertSame(0, Entry::query()->where('collection', $handle)->count());
    }

    public function test_blueprint_save_and_delete_vetoes_are_not_reported_as_success(): void
    {
        $handle = 'veto_' . bin2hex(random_bytes(4));
        // create takes Statamic's field format; generate takes the flat one.
        $fields = ['create' => [['handle' => 'title', 'field' => ['type' => 'text']]], 'generate' => [['handle' => 'title', 'type' => 'text']]];
        Event::listen(BlueprintSaving::class, fn () => false);

        foreach (['create', 'generate'] as $action) {
            $result = (new BlueprintsRouter)->execute(['action' => $action, 'namespace' => 'globals', 'handle' => $handle, 'fields' => $fields[$action], 'title' => 'Refused']);
            $this->assertFalse($result['success'], $action . ': ' . json_encode($result));
            $this->assertTrue($result['meta']['retry_requires_reconciliation'], $action);
            $this->assertNull(Blueprint::find('globals.' . $handle), $action);
        }

        Event::forget(BlueprintSaving::class);
        Blueprint::make($handle)->setNamespace('globals')->setContents(['title' => 'Before', 'fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]])->save();
        Event::listen(BlueprintSaving::class, fn () => false);
        Event::listen(BlueprintDeleting::class, fn () => false);

        $update = (new BlueprintsRouter)->execute(['action' => 'update', 'namespace' => 'globals', 'handle' => $handle, 'fields' => [['handle' => 'body', 'field' => ['type' => 'text']]]]);
        $this->assertFalse($update['success'], json_encode($update));
        $this->assertFalse(Blueprint::find('globals.' . $handle)?->hasField('body'));

        $delete = (new BlueprintsRouter)->execute(['action' => 'delete', 'namespace' => 'globals', 'handle' => $handle]);
        $this->assertFalse($delete['success'], json_encode($delete));
        $this->assertNotNull(Blueprint::find('globals.' . $handle));
    }

    public function test_a_failed_tree_move_reports_that_the_tree_may_have_changed(): void
    {
        $handle = 'contract-' . bin2hex(random_bytes(4));
        Collection::make($handle)->structureContents(['root' => false])->save();
        $entry = Entry::make()->collection($handle)->slug('page')->data(['title' => 'Page']);
        $entry->save();
        // The tree may be saved before the editor finds it did not apply.
        $editor = $this->createMock(CollectionTreeEditor::class);
        $editor->method('move')->willThrowException(new \RuntimeException('The tree save did not apply the requested parent.'));
        app()->instance(CollectionTreeEditor::class, $editor);

        $result = (new StructuresRouter)->execute(['action' => 'update', 'resource_type' => 'collection', 'handle' => $handle,
            'site' => 'default', 'data' => ['entry' => $entry->id(), 'parent' => null]]);

        $this->assertFalse($result['success'], json_encode($result));
        $this->assertSame('unknown', $result['meta']['write_effects'] ?? null, json_encode($result));
        $this->assertTrue($result['meta']['retry_requires_reconciliation']);
        $this->assertArrayHasKey('stache', $result['meta']['cache'] ?? [], 'the cache clear must be registered before the tree is touched');
    }

    public function test_a_failed_move_after_a_saved_edit_still_reports_the_committed_edit(): void
    {
        $handle = 'contract-' . bin2hex(random_bytes(4));
        Collection::make($handle)->structureContents(['root' => false])->save();
        $entry = Entry::make()->collection($handle)->slug('page')->published(false)->data(['title' => 'Before']);
        $entry->save();
        $editor = $this->createMock(CollectionTreeEditor::class);
        $editor->method('move')->willThrowException(new \RuntimeException('The tree changed during this operation.'));
        app()->instance(CollectionTreeEditor::class, $editor);

        $result = (new EntriesRouter)->execute(['action' => 'update', 'collection' => $handle, 'id' => $entry->id(),
            'data' => ['title' => 'After', 'parent' => 'elsewhere']]);

        $this->assertFalse($result['success'], json_encode($result));
        $this->assertSame('After', Entry::find($entry->id())->get('title'));
        // The title landed: "unknown" would hide a write that certainly happened.
        $this->assertSame('committed', $result['meta']['write_effects'] ?? null, json_encode($result));
    }

    public function test_each_call_reads_what_another_process_changed_since_the_last(): void
    {
        // A long-lived stdio server: the Control Panel edits a blueprint in
        // another process between two calls. Statamic memoizes blueprints in
        // named Blink stores, which a flush of the default store leaves alone.
        $handle = 'fresh_' . bin2hex(random_bytes(4));
        $blueprint = Blueprint::make($handle)->setNamespace('globals')->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]]);
        $blueprint->save();
        $read = fn () => (new BlueprintsRouter)->execute(['action' => 'get', 'namespace' => 'globals', 'handle' => $handle]);
        $this->assertStringNotContainsString('added_elsewhere', json_encode($read()) ?: '');

        file_put_contents($blueprint->path(), "fields:\n  - handle: title\n    field:\n      type: text\n  - handle: added_elsewhere\n    field:\n      type: text\n");

        $this->assertStringContainsString('added_elsewhere', json_encode($read()) ?: '');
    }
}
