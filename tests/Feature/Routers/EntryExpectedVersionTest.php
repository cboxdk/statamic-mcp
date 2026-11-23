<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

beforeEach(function () {
    Collection::make('ev_pages')->save();
    Blueprint::make('ev_pages')->setNamespace('collections.ev_pages')->setContents(['fields' => [
        ['handle' => 'title', 'field' => ['type' => 'text']],
    ]])->save();
    Entry::make()->id('ev-entry')->collection('ev_pages')->slug('one')->data(['title' => 'Original'])->published(false)->save();
});

function ev(array $arguments): array
{
    return (new EntriesRouter)->execute(['collection' => 'ev_pages', 'id' => 'ev-entry'] + $arguments);
}

it('reports a version that changes with every write and not without one', function () {
    $first = ev(['action' => 'get'])['data']['entry']['version'];

    expect(ev(['action' => 'get'])['data']['entry']['version'])->toBe($first);

    $updated = ev(['action' => 'update', 'data' => ['title' => 'Edited']]);
    expect($updated['data']['entry']['version'])->toBeString()->not->toBe($first)
        ->and(ev(['action' => 'get'])['data']['entry']['version'])->toBe($updated['data']['entry']['version']);
});

it('writes when the expected version is current', function () {
    $version = ev(['action' => 'get'])['data']['entry']['version'];

    $result = ev(['action' => 'update', 'expected_version' => $version, 'data' => ['title' => 'Edited']]);

    expect($result['success'])->toBeTrue(json_encode($result))
        ->and(Entry::find('ev-entry')->get('title'))->toBe('Edited');
});

it('refuses a stale version without saving, also when the change came from outside MCP', function () {
    $version = ev(['action' => 'get'])['data']['entry']['version'];

    // A Control Panel save, say: no MCP lock, no MCP version.
    Entry::find('ev-entry')->set('title', 'Changed in the CP')->save();

    foreach (['update', 'delete', 'publish'] as $action) {
        $result = ev(['action' => $action, 'expected_version' => $version, 'data' => ['title' => 'Overwrite']]);
        expect($result['success'])->toBeFalse()
            ->and(implode(' ', $result['errors']))->toContain('Version conflict')
            ->and($result['data']['code'])->toBe('VERSION_CONFLICT');
    }

    $entry = Entry::find('ev-entry');
    expect($entry)->not->toBeNull()
        ->and($entry->get('title'))->toBe('Changed in the CP')
        ->and($entry->published())->toBeFalse();
});

it('lets only the first of two writers with the same version through', function () {
    $version = ev(['action' => 'get'])['data']['entry']['version'];

    $first = ev(['action' => 'update', 'expected_version' => $version, 'data' => ['title' => 'First']]);
    $second = ev(['action' => 'update', 'expected_version' => $version, 'data' => ['title' => 'Second']]);

    expect($first['success'])->toBeTrue()
        ->and($second['success'])->toBeFalse()
        ->and(Entry::find('ev-entry')->get('title'))->toBe('First')
        // The conflict hands back what to retry against.
        ->and($second['data']['current_version'])->toBe($first['data']['entry']['version']);
});

it('refuses an empty expected version instead of writing unconditionally', function () {
    $result = ev(['action' => 'update', 'expected_version' => '', 'data' => ['title' => 'Edited']]);

    expect($result['success'])->toBeFalse()
        ->and(Entry::find('ev-entry')->get('title'))->toBe('Original');
});
