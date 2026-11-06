<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\GlobalsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\UsersRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Facades\Event;
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
            $result = (new GlobalsRouter)->execute(['action' => $action, 'handle' => $handle, 'site' => 'de', 'data' => ['title' => 'Forbidden']]);
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
}
