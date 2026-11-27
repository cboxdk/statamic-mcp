<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\UsersRouter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Fieldset;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/*
 * V05 exit criterion: an approval is bound to its owner and to one exact call,
 * expires, is decided only by POST with CSRF and a fresh elevated session, is
 * consumed once, and never stands in for authorization.
 */

beforeEach(function () {
    $this->apDir = sys_get_temp_dir() . '/mcp-approvals-' . bin2hex(random_bytes(8));
    config([
        'statamic.mcp.storage.operations_path' => $this->apDir,
        'statamic.mcp.security.force_web_mode' => true,
        'statamic.mcp.confirmation.enabled' => true,
        'statamic.users.elevated_sessions_enabled' => true,
        'statamic.editions.pro' => true,
    ]);

    Collection::make('ap_pages')->save();
    Blueprint::make('ap_pages')->setNamespace('collections.ap_pages')->setContents(['fields' => [
        ['handle' => 'title', 'field' => ['type' => 'text']],
    ]])->save();
    foreach (['ap-one', 'ap-two'] as $id) {
        Entry::make()->id($id)->collection('ap_pages')->slug($id)->data(['title' => $id])->published(false)->save();
    }

    Role::make()->handle('ap_editor')->permissions([
        'access cp', 'view mcp dashboard',
        'view ap_pages entries', 'edit ap_pages entries', 'delete ap_pages entries',
    ])->save();
    $this->apOwner = tap(User::make()->id('ap-owner')->email('owner@example.test')->assignRole('ap_editor'))->save();
    $this->apOther = tap(User::make()->id('ap-other')->email('other@example.test')->assignRole('ap_editor'))->save();
    $this->actingAs($this->apOwner);
});

afterEach(function () {
    File::deleteDirectory($this->apDir);
});

function apDelete(string $id, array $extra = []): array
{
    return (new EntriesRouter)->execute(['action' => 'delete', 'collection' => 'ap_pages', 'id' => $id] + $extra);
}

/** Decide as the current CP user, with or without an elevated session. */
function apDecide(string $approvalId, string $decision, bool $elevated = true): TestResponse
{
    $test = test();
    if ($elevated) {
        $test->withSession(['statamic_elevated_session' => now()->timestamp]);
    }

    return $test->postJson(cp_route('statamic-mcp.approvals.' . $decision, ['approval' => $approvalId]));
}

it('asks for approval instead of deleting, and lists it only for its owner', function () {
    $asked = apDelete('ap-one');

    expect($asked['success'])->toBeFalse()
        ->and($asked['data']['code'])->toBe('APPROVAL_REQUIRED')
        ->and($asked['data']['approval_id'])->toMatch('/^[a-f0-9]{64}$/')
        ->and(Entry::find('ap-one'))->not->toBeNull();

    $this->get(cp_route('statamic-mcp.approvals.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('statamic-mcp::McpApprovalsPage')->has('approvals', 1)
            ->where('approvals.0.id', $asked['data']['approval_id']));

    $this->actingAs($this->apOther)->get(cp_route('statamic-mcp.approvals.index'))
        ->assertInertia(fn ($page) => $page->has('approvals', 0));
});

it('runs the approved call once, and only that call', function () {
    $id = apDelete('ap-one')['data']['approval_id'];

    expect(apDelete('ap-one', ['approval_id' => $id])['data']['code'])->toBe('APPROVAL_PENDING');

    apDecide($id, 'approve')->assertOk();

    // Another call carrying the approval: refused, nothing deleted.
    expect(apDelete('ap-two', ['approval_id' => $id])['data']['code'])->toBe('APPROVAL_MISMATCH')
        ->and(Entry::find('ap-two'))->not->toBeNull();

    $ran = apDelete('ap-one', ['approval_id' => $id]);
    expect($ran['success'])->toBeTrue(json_encode($ran))
        ->and(Entry::find('ap-one'))->toBeNull();

    // A retry replays the result; the approval is consumed.
    expect(apDelete('ap-one', ['approval_id' => $id])['data']['approval_replay'])->toBeTrue();
});

it('lets only the owner decide, only by POST, and approving needs an elevated session', function () {
    $id = apDelete('ap-one')['data']['approval_id'];

    // A browser agent driving the owner's CP tab has the session and the CSRF
    // token, but not a fresh re-authentication.
    apDecide($id, 'approve', elevated: false)->assertForbidden();
    // GET never decides anything.
    expect($this->withSession(['statamic_elevated_session' => now()->timestamp])
        ->get(cp_route('statamic-mcp.approvals.index') . '/' . $id . '/approve')->status())->toBeIn([404, 405]);

    $this->actingAs($this->apOther);
    apDecide($id, 'approve')->assertStatus(409);
    apDecide($id, 'reject')->assertStatus(409);

    $this->actingAs($this->apOwner);
    expect(apDelete('ap-one', ['approval_id' => $id])['data']['code'])->toBe('APPROVAL_PENDING')
        ->and(Entry::find('ap-one'))->not->toBeNull();
});

it('rejecting ends the approval for good', function () {
    $id = apDelete('ap-one')['data']['approval_id'];

    apDecide($id, 'reject', elevated: false)->assertOk();

    expect(apDelete('ap-one', ['approval_id' => $id])['data']['code'])->toBe('APPROVAL_REJECTED');
    apDecide($id, 'approve')->assertStatus(409);
    expect(Entry::find('ap-one'))->not->toBeNull();
});

it('does not hold when the record changed after it was asked for', function () {
    $id = apDelete('ap-one')['data']['approval_id'];
    apDecide($id, 'approve')->assertOk();

    Entry::find('ap-one')->set('title', 'Changed in the CP')->save();

    expect(apDelete('ap-one', ['approval_id' => $id])['data']['code'])->toBe('APPROVAL_STALE')
        ->and(Entry::find('ap-one'))->not->toBeNull()
        ->and(apDelete('ap-one', ['approval_id' => $id])['data']['code'])->toBe('APPROVAL_REJECTED');
});

it('expires', function () {
    $id = apDelete('ap-one')['data']['approval_id'];
    $this->travel(config('statamic.mcp.confirmation.ttl', 900) + 1)->seconds();

    apDecide($id, 'approve')->assertStatus(409);
    expect(apDelete('ap-one', ['approval_id' => $id])['data']['code'])->toBe('APPROVAL_EXPIRED')
        ->and(Entry::find('ap-one'))->not->toBeNull();
});

it('binds fieldset approval and optimistic writes to the on-disk version', function () {
    $this->apOwner->makeSuper()->save();
    $fieldset = Fieldset::make('approval_fields')->setContents(['fields' => [
        ['handle' => 'title', 'field' => ['type' => 'text']],
    ]]);
    $fieldset->save();
    $router = new BlueprintsRouter;
    $call = ['action' => 'update_fieldset', 'handle' => 'approval_fields', 'title' => 'Agent title'];
    $version = $router->execute(['action' => 'get_fieldset', 'handle' => 'approval_fields'])['data']['fieldset']['version'];
    $asked = $router->execute($call);
    expect($asked['data']['code'])->toBe('APPROVAL_REQUIRED');
    apDecide($asked['data']['approval_id'], 'approve')->assertOk();
    file_put_contents($fieldset->path(), "title: Changed externally\nfields:\n  - handle: title\n    field:\n      type: textarea\n");
    $refused = $router->execute($call + ['approval_id' => $asked['data']['approval_id']]);
    expect($refused['data']['code'])->toBe('APPROVAL_STALE');

    config(['statamic.mcp.confirmation.enabled' => false]);
    $conflict = $router->execute($call + ['expected_version' => $version]);
    expect($conflict['data']['code'])->toBe('VERSION_CONFLICT');
    $read = $router->execute(['action' => 'get_fieldset', 'handle' => 'approval_fields']);
    expect($read['data']['fieldset']['title'])->toBe('Changed externally');
    $saved = $router->execute($call + ['expected_version' => $read['data']['fieldset']['version']]);
    expect($saved['success'])->toBeTrue(json_encode($saved));
});

it('never stands in for authorization: a permission lost after approval still refuses', function () {
    $id = apDelete('ap-one')['data']['approval_id'];
    apDecide($id, 'approve')->assertOk();

    Role::find('ap_editor')->permissions(['access cp', 'view mcp dashboard', 'view ap_pages entries'])->save();

    $result = apDelete('ap-one', ['approval_id' => $id]);
    expect($result['success'])->toBeFalse()
        ->and(implode(' ', $result['errors']))->toContain('Permission denied')
        ->and(Entry::find('ap-one'))->not->toBeNull();
});

it('asks for approval before any users action that can hand over an account, and never stores the new password', function () {
    Role::make()->handle('ap_admin')->permissions(['access cp', 'view mcp dashboard', 'view users', 'edit users', 'create users', 'assign roles', 'change passwords'])->save();
    $admin = tap(User::make()->id('ap-admin')->email('admin@example.test')->assignRole('ap_admin'))->save();
    $victim = tap(User::make()->id('ap-victim')->email('victim@example.test')->password('old-password-1'))->save();
    $this->actingAs($admin);
    $users = fn (array $arguments): array => (new UsersRouter)->execute(['resource_type' => 'user'] + $arguments);

    foreach ([
        ['action' => 'update', 'id' => 'ap-victim', 'data' => ['password' => 'taken-over-123']],
        ['action' => 'update', 'id' => 'ap-victim', 'data' => ['email' => 'attacker@example.test']],
        ['action' => 'assign_role', 'id' => 'ap-victim', 'role' => 'ap_admin'],
        ['action' => 'activate', 'id' => 'ap-victim'],
        ['action' => 'create', 'data' => ['email' => 'backdoor@example.test', 'password' => 'backdoor-123']],
    ] as $call) {
        $asked = $users($call);
        expect($asked['data']['code'] ?? null)->toBe('APPROVAL_REQUIRED', json_encode($call));
    }

    $fresh = User::find('ap-victim');
    expect($fresh->email())->toBe('victim@example.test')
        ->and(Hash::check('old-password-1', (string) $fresh->password()))->toBeTrue()
        ->and(User::findByEmail('backdoor@example.test'))->toBeNull();

    // The approval page shows what is asked, never the secret itself.
    $page = $this->get(cp_route('statamic-mcp.approvals.index'))->assertOk();
    expect($page->getContent())->not->toContain('taken-over-123')->not->toContain('backdoor-123');
    foreach (File::allFiles($this->apDir) as $file) {
        expect($file->getContents())->not->toContain('taken-over-123')->not->toContain('backdoor-123');
    }
});

it('needs the current password to change your own email or password', function () {
    Role::make()->handle('ap_self')->permissions(['access cp', 'view users', 'edit users'])->save();
    config(['statamic.mcp.confirmation.actions.users' => []]);
    $me = tap(User::make()->id('ap-me')->email('me@example.test')->password('my-password-1')->assignRole('ap_self'))->save();
    $this->actingAs($me);
    $update = fn (array $data): array => (new UsersRouter)->execute(['action' => 'update', 'resource_type' => 'user', 'id' => 'ap-me', 'data' => $data]);

    expect($update(['email' => 'thief@example.test'])['success'])->toBeFalse()
        ->and($update(['password' => 'stolen-pass-1', 'current_password' => 'wrong-guess'])['success'])->toBeFalse()
        ->and(User::find('ap-me')->email())->toBe('me@example.test');

    // A name is not a sign-in detail, and the right password is enough.
    expect($update(['name' => 'Me'])['success'])->toBeTrue()
        ->and($update(['password' => 'new-password-1', 'current_password' => 'my-password-1'])['success'])->toBeTrue()
        ->and(Hash::check('new-password-1', (string) User::find('ap-me')->password()))->toBeTrue();
});
