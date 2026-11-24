<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Actions\Contracts\LifecycleEvent;
use Cboxdk\StatamicMcp\Actions\Contracts\LifecycleObserver;
use Cboxdk\StatamicMcp\Actions\LifecycleObservers;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Testing\FakeLifecycleObserver;
use Illuminate\Support\Facades\File;
use Psr\Log\NullLogger;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/*
 * V06 exit criterion: built-in tools and approvals emit metadata-only lifecycle
 * events in the right order; a refused call never looks like an execution; a
 * replay is not a new write; an observer that throws after a write cannot
 * change the write's result.
 */

beforeEach(function () {
    $this->evDir = sys_get_temp_dir() . '/mcp-events-' . bin2hex(random_bytes(8));
    config(['statamic.mcp.storage.operations_path' => $this->evDir, 'statamic.editions.pro' => true]);
    $this->events = new FakeLifecycleObserver;
    $this->app->instance(LifecycleObservers::class, new LifecycleObservers(new NullLogger, [$this->events]));

    Collection::make('ev_pages')->save();
    Blueprint::make('ev_pages')->setNamespace('collections.ev_pages')->setContents(['fields' => [
        ['handle' => 'title', 'field' => ['type' => 'text']],
    ]])->save();
    Entry::make()->id('ev-one')->collection('ev_pages')->slug('ev-one')->data(['title' => 'Secret launch plan'])->save();
});

afterEach(function () {
    File::deleteDirectory($this->evDir);
});

function evNames(FakeLifecycleObserver $observer): array
{
    return array_map(fn (LifecycleEvent $event) => $event->eventName(), $observer->events);
}

function evNoContent(FakeLifecycleObserver $observer): void
{
    foreach ($observer->events as $event) {
        expect(json_encode($event->metadata(), JSON_THROW_ON_ERROR))->not->toContain('Secret', 'Changed title', 'do-not-leak');
    }
}

it('emits started then completed for a write, with committed effects and no content', function () {
    $result = (new EntriesRouter)->execute(['action' => 'update', 'collection' => 'ev_pages', 'id' => 'ev-one',
        'data' => ['title' => 'Changed title']]);

    expect($result['success'])->toBeTrue()
        ->and(evNames($this->events))->toBe(['execution.started', 'execution.completed']);
    $started = $this->events->events[0]->metadata();
    $completed = $this->events->events[1]->metadata();
    expect($completed['action_id'])->toBe('statamic-mcp/entries/update')
        ->and($completed['effects'])->toBe('committed')
        ->and($completed['operation_id'])->toBe($started['operation_id'])
        ->and($completed['transport'])->toBe('stdio');
    evNoContent($this->events);
});

it('reports a write the handler refused as failed with no effect, never completed', function () {
    (new EntriesRouter)->execute(['action' => 'update', 'collection' => 'ev_pages', 'id' => 'missing', 'data' => ['title' => 'x']]);

    expect(evNames($this->events))->toBe(['execution.started', 'execution.failed'])
        ->and($this->events->events[1]->metadata()['effects'])->toBe('none');
});

it('reports a denied call without a started event', function () {
    config(['statamic.mcp.security.force_web_mode' => true]);
    Role::make()->handle('ev_viewer')->permissions(['access cp', 'view ev_pages entries'])->save();
    $this->actingAs(tap(User::make()->id('ev-viewer')->email('v@example.test')->assignRole('ev_viewer'))->save());

    $result = (new EntriesRouter)->execute(['action' => 'delete', 'collection' => 'ev_pages', 'id' => 'ev-one']);

    expect($result['success'])->toBeFalse()
        ->and(evNames($this->events))->toBe(['execution.denied'])
        ->and(Entry::find('ev-one'))->not->toBeNull();
});

it('reports an idempotent replay as replayed, not as a second write', function () {
    $call = ['action' => 'update', 'collection' => 'ev_pages', 'id' => 'ev-one',
        'data' => ['title' => 'Changed title'], 'idempotency_key' => 'ev-key-00000001'];
    (new EntriesRouter)->execute($call);
    $first = $this->events->events[0]->metadata()['operation_id'];
    (new EntriesRouter)->execute($call);

    expect(evNames($this->events))->toBe(['execution.started', 'execution.completed', 'execution.replayed'])
        ->and($this->events->events[2]->metadata()['operation_id'])->toBe($first)
        ->and($this->events->events[2]->metadata()['effects'])->toBe('none');
});

it('follows an approval from request to consumption, and records the person\'s rejection', function () {
    config(['statamic.mcp.security.force_web_mode' => true, 'statamic.mcp.confirmation.enabled' => true,
        'statamic.mcp.approvals.channels' => 'none', 'statamic.users.elevated_sessions_enabled' => true]);
    Entry::make()->id('ev-two')->collection('ev_pages')->slug('ev-two')->data(['title' => 'Two'])->save();
    Role::make()->handle('ev_editor')->permissions(['access cp', 'view mcp dashboard',
        'view ev_pages entries', 'edit ev_pages entries', 'delete ev_pages entries'])->save();
    $this->actingAs(tap(User::make()->id('ev-owner')->email('o@example.test')->assignRole('ev_editor'))->save());

    $id = (new EntriesRouter)->execute(['action' => 'delete', 'collection' => 'ev_pages', 'id' => 'ev-one'])['data']['approval_id'];
    $this->withSession(['statamic_elevated_session' => now()->timestamp])
        ->postJson(cp_route('statamic-mcp.approvals.approve', ['approval' => $id]))->assertOk();
    (new EntriesRouter)->execute(['action' => 'delete', 'collection' => 'ev_pages', 'id' => 'ev-one', 'approval_id' => $id]);

    expect(evNames($this->events))->toBe(['approval.requested', 'approval.approved',
        'execution.started', 'execution.completed', 'approval.consumed']);
    $approved = $this->events->events[1]->metadata();
    expect($approved['approval_id'])->toBe($id)->and($approved['via'])->toBe('control_panel')
        ->and($this->events->events[2]->metadata()['operation_id'])->toBe($id);
    evNoContent($this->events);

    $this->events->events = [];
    $other = (new EntriesRouter)->execute(['action' => 'delete', 'collection' => 'ev_pages', 'id' => 'ev-two'])['data']['approval_id'];
    $this->postJson(cp_route('statamic-mcp.approvals.reject', ['approval' => $other]))->assertOk();

    expect(evNames($this->events))->toBe(['approval.requested', 'approval.rejected'])
        ->and($this->events->events[1]->metadata()['reason'])->toBe('person');
});

it('keeps the write and its result when an observer throws after it', function () {
    $this->app->instance(LifecycleObservers::class, new LifecycleObservers(new NullLogger, [
        new class implements LifecycleObserver
        {
            public function observe(LifecycleEvent $event): void
            {
                throw new RuntimeException('exporter down do-not-leak');
            }
        },
        $this->events,
    ]));

    $result = (new EntriesRouter)->execute(['action' => 'update', 'collection' => 'ev_pages', 'id' => 'ev-one',
        'data' => ['title' => 'Changed title']]);

    expect($result['success'])->toBeTrue()
        ->and(json_encode($result))->not->toContain('do-not-leak')
        ->and(Entry::find('ev-one')->get('title'))->toBe('Changed title')
        ->and(evNames($this->events))->toBe(['execution.started', 'execution.completed']);
});
