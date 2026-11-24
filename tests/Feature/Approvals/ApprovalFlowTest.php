<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
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

it('never stands in for authorization: a permission lost after approval still refuses', function () {
    $id = apDelete('ap-one')['data']['approval_id'];
    apDecide($id, 'approve')->assertOk();

    Role::find('ap_editor')->permissions(['access cp', 'view mcp dashboard', 'view ap_pages entries'])->save();

    $result = apDelete('ap-one', ['approval_id' => $id]);
    expect($result['success'])->toBeFalse()
        ->and(implode(' ', $result['errors']))->toContain('Permission denied')
        ->and(Entry::find('ap-one'))->not->toBeNull();
});
