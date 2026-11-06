<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature;

use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Http\Middleware\AuthenticateForMcp;
use Cboxdk\StatamicMcp\Http\Middleware\HandleMcpCors;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\UsersRouter;
use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Statamic\Events\UserBlueprintFound;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\User;

class ReviewSecurityRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        User::make()->id('review-user')->email('oauth-review@example.test')->save();
    }

    /** @param list<string> $permissions */
    private function actor(array $permissions): \Statamic\Contracts\Auth\User
    {
        config(['statamic.editions.pro' => true, 'statamic.mcp.security.force_web_mode' => true]);
        $suffix = bin2hex(random_bytes(4));
        Role::make()->handle('review-' . $suffix)->permissions(['access cp', ...$permissions])->save();
        $u = User::make()->id('review-' . $suffix)->email($suffix . '@example.test')->assignRole('review-' . $suffix);
        $u->password('review-password');
        $u->save();
        $this->actingAs($u);

        return $u;
    }

    public function test_same_origin_uses_configured_url_not_untrusted_host(): void
    {
        config(['app.url' => 'https://cms.example/subdirectory', 'statamic.mcp.web.allowed_origins' => []]);
        foreach (['https://cms.example' => 200, 'https://attacker.example' => 403] as $origin => $status) {
            $request = Request::create($origin . '/mcp/statamic', 'POST');
            $request->headers->set('Origin', $origin);
            $response = (new HandleMcpCors)->handle($request, fn () => response('accepted'));
            $this->assertSame($status, $response->getStatusCode());
        }
    }

    public function test_editor_can_create_a_draft_without_publish_permission(): void
    {
        $handle = 'review-' . bin2hex(random_bytes(4));
        Collection::make($handle)->save();
        $this->actor(["create {$handle} entries"]);
        $result = (new EntriesRouter)->execute(['action' => 'create', 'collection' => $handle, 'data' => ['title' => 'Draft']]);
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertFalse(Entry::find($result['data']['entry']['id'])->published());
    }

    public function test_renaming_a_token_preserves_refresh_and_rotated_successor_works(): void
    {
        $driver = app(OAuthDriver::class);
        $service = app(TokenService::class);
        $client = $driver->registerClient('review', ['https://example.test/callback']);
        $refresh = $driver->createRefreshToken('review-user', $client->clientId, ['entries:read']);
        $access = $service->createToken('review-user', 'before', [TokenScope::EntriesRead], null, $client->clientId)['model'];
        $service->updateToken($access->id, name: 'after', scopes: [TokenScope::EntriesRead]);
        $args = ['grant_type' => 'refresh_token', 'client_id' => $client->clientId, 'refresh_token' => $refresh];
        $first = $this->postJson('/mcp/oauth/token', $args)->assertOk();
        $args['refresh_token'] = $first->json('refresh_token');
        $this->postJson('/mcp/oauth/token', $args)->assertOk();
    }

    public function test_revoke_all_removes_orphan_refresh_grants(): void
    {
        $driver = app(OAuthDriver::class);
        $client = $driver->registerClient('review', ['https://example.test/callback']);
        $refresh = $driver->createRefreshToken('review-user', $client->clientId, ['entries:read']);
        app(TokenService::class)->revokeAllForUser('review-user');
        $this->postJson('/mcp/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $client->clientId, 'refresh_token' => $refresh])->assertStatus(400);
    }

    public function test_basic_auth_is_removed_even_when_legacy_config_enables_it(): void
    {
        $user = $this->actor([]);
        $request = Request::create('/mcp/statamic', 'POST');
        $request->headers->set('Authorization', 'Basic ' . base64_encode($user->email() . ':review-password'));
        config(['statamic.mcp.web.basic_auth_enabled' => false]);
        $this->assertSame(401, app(AuthenticateForMcp::class)->handle($request, fn () => response('accepted'))->getStatusCode());
        config(['statamic.mcp.web.basic_auth_enabled' => true]);
        $this->assertSame(401, app(AuthenticateForMcp::class)->handle($request, fn () => response('accepted'))->getStatusCode());
    }

    public function test_inactive_user_cannot_refresh(): void
    {
        $driver = app(OAuthDriver::class);
        $client = $driver->registerClient('review', ['https://example.test/callback']);
        $refresh = $driver->createRefreshToken('review-user', $client->clientId, ['entries:read']);
        User::find('review-user')->set('status', 'inactive')->save();
        $args = ['grant_type' => 'refresh_token', 'client_id' => $client->clientId, 'refresh_token' => $refresh];
        $this->postJson('/mcp/oauth/token', $args)->assertStatus(400);
        User::find('review-user')->set('status', 'active')->save();
        $this->postJson('/mcp/oauth/token', $args)->assertStatus(400);
    }

    public function test_editor_can_restore_a_revision_without_publishing(): void
    {
        config(['statamic.revisions.enabled' => true]);
        $handle = 'review-' . bin2hex(random_bytes(4));
        Collection::make($handle)->revisionsEnabled(true)->save();
        $user = $this->actor(["edit {$handle} entries"]);
        $entry = Entry::make()->collection($handle)->slug('restore')->data(['title' => 'Before'])->published(false);
        $entry->save();
        $entry->store(['user' => $user, 'message' => 'Before']);
        $revision = $entry->revisions()->first();
        $entry->set('title', 'After')->save();
        $result = (new EntriesRouter)->execute(['action' => 'restore_revision', 'collection' => $handle, 'id' => $entry->id(), 'revision_id' => (string) $revision->date()->timestamp]);
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertFalse(Entry::find($entry->id())->published());
        $this->assertSame('Before', Entry::find($entry->id())->get('title'));
    }

    public function test_localized_get_and_update_check_the_target_site(): void
    {
        config(['statamic.system.multisite' => true]);
        Site::setSites(['en' => ['name' => 'English', 'locale' => 'en_US', 'url' => '/'], 'de' => ['name' => 'Deutsch', 'locale' => 'de_DE', 'url' => '/de/']]);
        $handle = 'review-' . bin2hex(random_bytes(4));
        Collection::make($handle)->sites(['en', 'de'])->save();
        $this->actor(['access en site', "view {$handle} entries", "edit {$handle} entries"]);
        $source = Entry::make()->collection($handle)->locale('en')->slug('source')->data(['title' => 'English'])->published(false);
        $source->save();
        $target = $source->makeLocalization('de')->data(['title' => 'Deutsch']);
        $target->save();
        foreach (['get', 'update'] as $action) {
            $result = (new EntriesRouter)->execute(['action' => $action, 'collection' => $handle, 'id' => $source->id(), 'site' => 'de', 'data' => ['title' => 'Forbidden']]);
            $this->assertFalse($result['success'], json_encode($result));
            $this->assertStringContainsString('Permission denied', implode(' ', $result['errors']));
        }
        $this->assertSame('Deutsch', Entry::find($target->id())->get('title'));
        $allowed = (new EntriesRouter)->execute(['action' => 'get', 'collection' => $handle, 'id' => $source->id(), 'site' => 'en']);
        $this->assertTrue($allowed['success'], json_encode($allowed));
    }

    public function test_basic_auth_refuses_password_only_for_two_factor_user(): void
    {
        config(['statamic.mcp.web.basic_auth_enabled' => true]);
        $u = $this->actor([]);
        $u->set('two_factor_secret', encrypt('SYNTHETICSECRET'))->set('two_factor_confirmed_at', now()->timestamp)->save();
        $this->assertTrue($u->hasEnabledTwoFactorAuthentication());
        $r = Request::create('/mcp/statamic', 'POST');
        $r->headers->set('Authorization', 'Basic ' . base64_encode($u->email() . ':review-password'));
        $response = app(AuthenticateForMcp::class)->handle($r, fn () => response('accepted', 200));
        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_scope_narrowing_survives_refresh(): void
    {
        $d = app(OAuthDriver::class);
        $t = app(TokenService::class);
        $c = $d->registerClient('review', ['https://example.test/callback']);
        $r = $d->createRefreshToken('review-user', $c->clientId, ['entries:read', 'entries:write']);
        $a = $t->createToken('review-user', 'review', [TokenScope::EntriesRead, TokenScope::EntriesWrite], null, $c->clientId)['model'];
        $t->updateToken($a->id, scopes: [TokenScope::EntriesRead]);
        $response = $this->postJson('/mcp/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $c->clientId, 'refresh_token' => $r]);
        $response->assertStatus(400);
        $this->assertSame(['entries:read'], $t->listTokensForUser('review-user')->first()?->scopes);
    }

    public function test_revoke_all_prevents_refresh(): void
    {
        $d = app(OAuthDriver::class);
        $t = app(TokenService::class);
        $c = $d->registerClient('review', ['https://example.test/callback']);
        $r = $d->createRefreshToken('review-user', $c->clientId, ['entries:read']);
        $t->createToken('review-user', 'review', [TokenScope::EntriesRead], null, $c->clientId);
        $t->revokeAllForUser('review-user');
        $this->postJson('/mcp/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $c->clientId, 'refresh_token' => $r])->assertStatus(400);
    }

    public function test_replayed_refresh_revokes_its_successor(): void
    {
        $d = app(OAuthDriver::class);
        $c = $d->registerClient('review', ['https://example.test/callback']);
        $r = $d->createRefreshToken('review-user', $c->clientId, ['entries:read']);
        $args = ['grant_type' => 'refresh_token', 'client_id' => $c->clientId, 'refresh_token' => $r];
        $first = $this->postJson('/mcp/oauth/token', $args)->assertOk();
        $this->postJson('/mcp/oauth/token', $args)->assertStatus(400);
        $args['refresh_token'] = $first->json('refresh_token');
        $this->postJson('/mcp/oauth/token', $args)->assertStatus(400);
    }

    public function test_user_reads_exclude_two_factor_ciphertexts(): void
    {
        $u = $this->actor(['view users']);
        $u->set('two_factor_secret', encrypt('SYNTHETICSECRET'))->set('two_factor_recovery_codes', encrypt('["fake-code"]'))->save();
        $r = (new UsersRouter)->execute(['action' => 'get', 'resource_type' => 'user', 'id' => $u->id()]);
        $this->assertTrue($r['success'], json_encode($r));
        $this->assertArrayNotHasKey('two_factor_secret', $r['data']['user']['data']);
    }

    public function test_user_reads_preserve_custom_blueprint_fields(): void
    {
        $user = $this->actor(['view users']);
        Event::listen(
            UserBlueprintFound::class,
            fn ($event) => $event->blueprint->ensureField('department', ['type' => 'text']),
        );
        $user->set('department', 'Editorial')->set('internal_secret', 'private')->save();
        $result = (new UsersRouter)->execute(['action' => 'get', 'resource_type' => 'user', 'id' => $user->id()]);
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame('Editorial', $result['data']['user']['data']['department']);
        $this->assertArrayNotHasKey('internal_secret', $result['data']['user']['data']);
    }

    public function test_role_view_works_with_statamic_edit_roles_permission(): void
    {
        $this->actor(['edit roles']);
        $r = (new UsersRouter)->execute(['action' => 'list', 'resource_type' => 'role']);
        $this->assertTrue($r['success'], json_encode($r));
    }

    public function test_disallowed_origin_is_rejected_before_handler(): void
    {
        config(['statamic.mcp.web.allowed_origins' => ['https://trusted.example']]);
        $r = Request::create('/mcp/statamic', 'POST');
        $r->headers->set('Origin', 'https://untrusted.example');
        $response = (new HandleMcpCors)->handle($r, fn () => response('mutation executed', 200));
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_update_cannot_publish_without_publish_permission(): void
    {
        $h = 'review-' . bin2hex(random_bytes(4));
        Collection::make($h)->save();
        $u = $this->actor(["edit {$h} entries"]);
        $e = Entry::make()->id('review-publish')->collection($h)->slug('draft')->data(['title' => 'Draft'])->published(false);
        $e->save();
        $this->assertFalse($u->can('publish', $e));
        $r = (new EntriesRouter)->execute(['action' => 'update', 'collection' => $h, 'id' => $e->id(), 'data' => ['published' => true]]);
        $this->assertFalse(Entry::find($e->id())->published(), json_encode($r));
    }

    public function test_working_copy_publish_cannot_bypass_author_policy(): void
    {
        config(['statamic.revisions.enabled' => true]);
        $h = 'review-' . bin2hex(random_bytes(4));
        Collection::make($h)->revisionsEnabled(true)->save();
        Blueprint::make('post')->setNamespace('collections.' . $h)->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'author', 'field' => ['type' => 'users', 'max_items' => 1]],
        ]])->save();
        $u = $this->actor(["publish {$h} entries"]);
        $owner = User::make()->id('review-owner')->email('owner@example.test');
        $owner->save();
        $e = Entry::make()->id('review-working-copy')->collection($h)->slug('draft')->data(['title' => 'Live', 'author' => $owner->id()])->published(true);
        $e->save();
        $e->makeWorkingCopy()->user($owner)->save();
        $this->assertFalse($u->can('publish', $e));
        $r = (new EntriesRouter)->execute(['action' => 'publish_working_copy', 'collection' => $h, 'id' => $e->id()]);
        $this->assertFalse($r['success'], json_encode($r));
        $this->assertStringContainsString('Permission denied', implode(' ', $r['errors']));
    }

    public function test_structure_handle_cannot_escape_collection_directory(): void
    {
        $this->actor(['configure collections']);
        $handle = '../review-escape-' . bin2hex(random_bytes(4));
        $path = Collection::make($handle)->path();
        try {
            $r = (new StructuresRouter)->execute(['action' => 'create', 'resource_type' => 'collection', 'data' => ['handle' => $handle, 'title' => 'Review fixture']]);
            $this->assertFileDoesNotExist($path, json_encode($r));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_entry_list_honors_site_permission(): void
    {
        config(['statamic.system.multisite' => true]);
        Site::setSites(['en' => ['name' => 'English', 'locale' => 'en_US', 'url' => '/'], 'de' => ['name' => 'Deutsch', 'locale' => 'de_DE', 'url' => '/de/']]);
        $h = 'review-' . bin2hex(random_bytes(4));
        Collection::make($h)->sites(['en', 'de'])->save();
        $u = $this->actor(['access en site', "view {$h} entries"]);
        $e = Entry::make()->id('review-entry')->collection($h)->locale('de')->slug('private')->data(['title' => 'Private draft'])->published(false);
        $e->save();
        $this->assertFalse($u->can('view', $e));
        $r = (new EntriesRouter)->execute(['action' => 'list', 'collection' => $h, 'site' => 'de', 'include_unpublished' => true]);
        $this->assertTrue(! $r['success'] || count($r['data']['entries']) === 0, json_encode($r));
    }
}
