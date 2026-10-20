<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Facades\User;

/**
 * statamic-entries resolves an entry by collection + id and used to act on it
 * with only a token scope and a site-wide resource-policy check — never the
 * per-entry EntryPolicy. A delegated, non-super caller with "edit {collection}
 * entries" but not the "other authors" variant could therefore edit, publish
 * or delete entries authored by someone else, and a caller barred from a site
 * could still write that site's entries (#68).
 *
 * The guard only applies in web context (CLI is the trusted operator and super
 * admins pass via EntryPolicy::before), so these tests force web mode and act
 * as a real non-super user with a granular role.
 */
class EntryPolicyBoundaryTest extends TestCase
{
    private EntriesRouter $router;

    private string $collectionHandle;

    protected function setUp(): void
    {
        parent::setUp();
        config(['statamic.editions.pro' => true]);
        config(['statamic.mcp.security.force_web_mode' => true]);

        $this->router = new EntriesRouter;
        $this->collectionHandle = 'posts-' . bin2hex(random_bytes(4));

        Collection::make($this->collectionHandle)
            ->title('Posts')
            ->save();

        // A blueprint with an `author` field is what makes EntryPolicy treat an
        // entry written by someone else as "another author's".
        Blueprint::make('post')->setNamespace("collections.{$this->collectionHandle}")->setContents([
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'author', 'field' => ['type' => 'users', 'max_items' => 1]],
            ],
        ])->save();

        Stache::refresh();
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function actAs(array $permissions, bool $super = false): UserContract
    {
        $handle = 'role-' . bin2hex(random_bytes(4));
        Role::make()->handle($handle)->title('Role')->permissions(['access cp', ...$permissions])->save();
        $user = User::make()->id('u-' . bin2hex(random_bytes(4)))->email(bin2hex(random_bytes(4)) . '@example.test');
        if ($super) {
            $user->makeSuper();
        } else {
            $user->assignRole($handle);
        }
        $user->save();
        $this->actingAs($user);

        return $user;
    }

    private function makeEntryAuthoredBy(string $authorId): string
    {
        $id = 'entry-' . bin2hex(random_bytes(4));
        Entry::make()
            ->id($id)
            ->collection($this->collectionHandle)
            ->slug($id)
            ->data(['title' => 'A post', 'author' => $authorId])
            ->published(true)
            ->save();
        Stache::refresh();

        return $id;
    }

    public function test_cannot_update_another_authors_entry_without_the_other_authors_permission(): void
    {
        $owner = User::make()->id('owner-' . bin2hex(random_bytes(4)))->email(bin2hex(random_bytes(4)) . '@example.test');
        $owner->save();
        $entryId = $this->makeEntryAuthoredBy($owner->id());

        // Caller may edit the collection's own entries, but not other authors'.
        $this->actAs(["edit {$this->collectionHandle} entries"]);

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entryId,
            'data' => ['title' => 'Hijacked'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('may not edit', $result['errors'][0] ?? '');
        // The stored entry is untouched.
        $this->assertSame('A post', Entry::find($entryId)->get('title'));
    }

    public function test_cannot_delete_or_publish_another_authors_entry(): void
    {
        $owner = User::make()->id('owner-' . bin2hex(random_bytes(4)))->email(bin2hex(random_bytes(4)) . '@example.test');
        $owner->save();
        $entryId = $this->makeEntryAuthoredBy($owner->id());

        $this->actAs([
            "publish {$this->collectionHandle} entries",
            "delete {$this->collectionHandle} entries",
        ]);

        $deleteResult = $this->router->execute([
            'action' => 'delete',
            'collection' => $this->collectionHandle,
            'id' => $entryId,
        ]);
        $this->assertFalse($deleteResult['success']);
        $this->assertStringContainsString('may not delete', $deleteResult['errors'][0] ?? '');
        $this->assertNotNull(Entry::find($entryId), 'entry must still exist');

        $unpublishResult = $this->router->execute([
            'action' => 'unpublish',
            'collection' => $this->collectionHandle,
            'id' => $entryId,
        ]);
        $this->assertFalse($unpublishResult['success']);
        $this->assertStringContainsString('may not publish', $unpublishResult['errors'][0] ?? '');
    }

    public function test_author_may_update_their_own_entry(): void
    {
        $caller = $this->actAs(["edit {$this->collectionHandle} entries"]);
        $entryId = $this->makeEntryAuthoredBy($caller->id());

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entryId,
            'data' => ['title' => 'Edited by owner'],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame('Edited by owner', Entry::find($entryId)->get('title'));
    }

    public function test_super_admin_may_update_any_entry(): void
    {
        $owner = User::make()->id('owner-' . bin2hex(random_bytes(4)))->email(bin2hex(random_bytes(4)) . '@example.test');
        $owner->save();
        $entryId = $this->makeEntryAuthoredBy($owner->id());

        $this->actAs([], super: true);

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entryId,
            'data' => ['title' => 'Edited by super'],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame('Edited by super', Entry::find($entryId)->get('title'));
    }

    public function test_viewing_is_still_allowed_with_a_plain_view_permission(): void
    {
        $owner = User::make()->id('owner-' . bin2hex(random_bytes(4)))->email(bin2hex(random_bytes(4)) . '@example.test');
        $owner->save();
        $entryId = $this->makeEntryAuthoredBy($owner->id());

        // A granular caller: may view, but not edit other authors' entries.
        $this->actAs(["view {$this->collectionHandle} entries"]);

        $result = $this->router->execute([
            'action' => 'get',
            'collection' => $this->collectionHandle,
            'id' => $entryId,
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
    }

    public function test_cannot_write_an_entry_in_a_site_the_caller_cannot_access(): void
    {
        config(['statamic.system.multisite' => true]);
        Site::setSites([
            'en' => ['name' => 'English', 'locale' => 'en_US', 'url' => '/'],
            'de' => ['name' => 'Deutsch', 'locale' => 'de_DE', 'url' => '/de/'],
        ]);

        $handle = 'pages-' . bin2hex(random_bytes(4));
        Collection::make($handle)->title('Pages')->sites(['en', 'de'])->save();
        Blueprint::make('page')->setNamespace("collections.{$handle}")->setContents([
            'fields' => [['handle' => 'title', 'field' => ['type' => 'text']]],
        ])->save();

        $caller = $this->actAs([
            'access en site',
            "edit {$handle} entries",
            "edit other authors {$handle} entries",
        ]);

        $entryId = 'de-' . bin2hex(random_bytes(4));
        Entry::make()
            ->id($entryId)
            ->collection($handle)
            ->locale('de')
            ->slug($entryId)
            ->data(['title' => 'Seite', 'author' => $caller->id()])
            ->save();
        Stache::refresh();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $handle,
            'id' => $entryId,
            'site' => 'de',
            'data' => ['title' => 'Hijacked'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('may not edit', $result['errors'][0] ?? '');
        $this->assertSame('Seite', Entry::find($entryId)->get('title'));
    }
}
