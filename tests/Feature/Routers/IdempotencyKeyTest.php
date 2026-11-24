<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Actions\ActionEffect;
use Cboxdk\StatamicMcp\Actions\Contracts\OperationStore;
use Cboxdk\StatamicMcp\Actions\Operations\IdempotentWrite;
use Cboxdk\StatamicMcp\Actions\Operations\Operation;
use Cboxdk\StatamicMcp\Actions\Operations\OperationState;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Illuminate\Support\Facades\File;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

beforeEach(function () {
    $this->ikDir = sys_get_temp_dir() . '/mcp-idem-router-' . bin2hex(random_bytes(8));
    config(['statamic.mcp.storage.operations_path' => $this->ikDir]);

    Collection::make('ik_pages')->save();
    Blueprint::make('ik_pages')->setNamespace('collections.ik_pages')->setContents(['fields' => [
        ['handle' => 'title', 'field' => ['type' => 'text']],
    ]])->save();
    Entry::make()->id('ik-entry')->collection('ik_pages')->slug('one')->data(['title' => 'Original'])->published(false)->save();
});

afterEach(function () {
    File::deleteDirectory($this->ikDir);
});

function ik(array $arguments): array
{
    return (new EntriesRouter)->execute($arguments + ['collection' => 'ik_pages', 'id' => 'ik-entry', 'action' => 'update']);
}

it('replays the first result for a retry with the same key and writes once', function () {
    $first = ik(['idempotency_key' => 'change-0001', 'data' => ['title' => 'First']]);

    // Someone else changes the entry; a replay must not write over it.
    Entry::find('ik-entry')->set('title', 'Changed in the CP')->save();
    $retry = ik(['idempotency_key' => 'change-0001', 'data' => ['title' => 'First']]);

    expect($first['success'])->toBeTrue(json_encode($first))
        ->and($retry['success'])->toBeTrue(json_encode($retry))
        ->and($retry['data']['idempotent_replay'])->toBeTrue()
        ->and($retry['data']['entry']['version'])->toBe($first['data']['entry']['version'])
        ->and(Entry::find('ik-entry')->get('title'))->toBe('Changed in the CP');
});

it('refuses the same key for a different request without saving', function () {
    ik(['idempotency_key' => 'change-0002', 'data' => ['title' => 'First']]);

    $reused = ik(['idempotency_key' => 'change-0002', 'data' => ['title' => 'Other']]);

    expect($reused['success'])->toBeFalse()
        ->and($reused['data']['code'])->toBe('IDEMPOTENCY_KEY_REUSED')
        ->and(Entry::find('ik-entry')->get('title'))->toBe('First');
});

it('lets the same arguments through again under a new key', function () {
    ik(['idempotency_key' => 'change-0003', 'data' => ['title' => 'First']]);
    Entry::find('ik-entry')->set('title', 'Reverted')->save();

    $again = ik(['idempotency_key' => 'change-0004', 'data' => ['title' => 'First']]);

    expect($again['success'])->toBeTrue()
        ->and($again['data'])->not->toHaveKey('idempotent_replay')
        ->and(Entry::find('ik-entry')->get('title'))->toBe('First');
});

it('refuses a malformed key instead of writing unconditionally', function (mixed $key) {
    $result = ik(['idempotency_key' => $key, 'data' => ['title' => 'Edited']]);

    expect($result['success'])->toBeFalse()
        ->and(Entry::find('ik-entry')->get('title'))->toBe('Original');
})->with(['', 'short', 'has spaces in it', str_repeat('a', 129)]);

it('answers OUTCOME_UNKNOWN with the current version for a key whose write never reported', function () {
    // The record a crashed worker leaves: claimed, executing, no result.
    $arguments = ['collection' => 'ik_pages', 'id' => 'ik-entry', 'action' => 'update', 'data' => ['title' => 'Lost']];
    $intent = IdempotentWrite::intent('change-0005', 'stdio', 'stdio', 'entries', 'update', ActionEffect::Edit,
        'default', 'operator', 'stdio', $arguments);
    $store = app(OperationStore::class);
    $prepared = new Operation($intent->operationId, $intent->bindingDigest, OperationState::Prepared, 1, time() + 600,
        encryptedIntent: encrypt($intent->toJson(), false));
    $store->create($prepared);
    $ready = $prepared->transition(OperationState::Ready);
    $store->transition($prepared, $ready);
    $store->transition($ready, $ready->transition(OperationState::Executing));

    $result = ik(['idempotency_key' => 'change-0005', 'data' => ['title' => 'Lost']]);
    $version = (new EntriesRouter)->execute(['collection' => 'ik_pages', 'id' => 'ik-entry', 'action' => 'get'])['data']['entry']['version'];

    expect($result['success'])->toBeFalse()
        ->and($result['data']['code'])->toBe('OUTCOME_UNKNOWN')
        ->and($result['data']['current_version'])->toBe($version)
        ->and(Entry::find('ik-entry')->get('title'))->toBe('Original');
});

it('spends a key whose write saved nothing', function () {
    $failed = ik(['idempotency_key' => 'change-0006', 'id' => 'missing-entry', 'data' => ['title' => 'X']]);
    $retry = ik(['idempotency_key' => 'change-0006', 'id' => 'missing-entry', 'data' => ['title' => 'X']]);

    expect($failed['success'])->toBeFalse()
        ->and($retry['data']['code'])->toBe('IDEMPOTENCY_KEY_SPENT');
});
