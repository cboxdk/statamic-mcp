<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Statamic\Facades\Blink;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\User;

function collectionTreeRegressionFixture(string $route = '/{parent_uri}/{slug}'): void
{
    $collection = Collection::make('tree_pages')->title('Pages')->sites(['default'])
        ->routes($route)->structureContents(['root' => false, 'max_depth' => 5]);
    $collection->save();
    Blueprint::make('page')->setNamespace('collections.tree_pages')->setContents([
        'tabs' => ['main' => ['sections' => [['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
        ]]]]],
    ])->save();

    foreach (['a' => 'a', 'b' => 'b', 'child' => 'old', 'existing' => 'taken'] as $id => $title) {
        Blink::flush();
        Entry::make()->id($id)->collection('tree_pages')->locale('default')
            ->slug($id)->published(false)->data(['title' => $title])->save();
    }

    Blink::flush();
    $collection->structure()->in('default')->tree([
        ['entry' => 'a', 'children' => [['entry' => 'child']]],
        ['entry' => 'b', 'children' => [['entry' => 'existing']]],
    ])->save();
    Blink::flush();
}

it('hides denied parent metadata on entry reads and content writes', function (string $domain) {
    collectionTreeRegressionFixture();
    config(["statamic.mcp.tools.{$domain}.denied_fields" => ['parent']]);
    $router = new EntriesRouter;

    foreach (['get', 'update'] as $action) {
        $result = $router->execute([
            'action' => $action, 'collection' => 'tree_pages', 'id' => 'child',
            'data' => ['title' => 'Updated'],
        ]);
        expect($result['success'])->toBeTrue()
            ->and($result['data']['entry'])->not->toHaveKey('parent');
    }
})->with(['entries', 'structures']);

it('filters collection trees for every caller and both field policies', function (string $context, string $domain) {
    collectionTreeRegressionFixture();
    config(["statamic.mcp.tools.{$domain}.denied_fields" => ['tree']]);

    if ($context !== 'cli') {
        config([
            'statamic.editions.pro' => true,
            'statamic.mcp.security.force_web_mode' => true,
            'statamic.mcp.web.enabled' => true,
        ]);
        $user = User::make()->email('tree@example.test');
        if ($context === 'super') {
            $user->makeSuper();
        } else {
            Role::make()->handle('tree-viewer')->permissions([
                'access cp', 'access default site', 'view tree_pages entries',
            ])->save();
            $user->assignRole('tree-viewer');
        }
        $user->save();
        $this->actingAs($user);
    }

    $result = (new StructuresRouter)->execute([
        'action' => 'get', 'resource_type' => 'collection', 'handle' => 'tree_pages',
        'include_tree' => true,
    ]);
    expect($result['success'])->toBeTrue()
        ->and($result['data']['collection'])->not->toHaveKey('tree');
})->with(['cli', 'super', 'editor'])->with(['entries', 'structures']);

it('rejects route collisions using the proposed fields before saving either file', function () {
    collectionTreeRegressionFixture('/{parent_uri}/{title}');
    $entry = Entry::find('child');
    $entryPath = $entry->path();
    $treePath = $entry->collection()->structure()->in('default')->path();
    $entryBefore = file_get_contents($entryPath);
    $treeBefore = file_get_contents($treePath);

    $result = (new EntriesRouter)->execute([
        'action' => 'update', 'collection' => 'tree_pages', 'id' => 'child',
        'data' => ['title' => 'taken', 'parent' => 'b'],
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['errors'][0])->toContain('duplicate entry URI')
        ->and(file_get_contents($entryPath))->toBe($entryBefore)
        ->and(file_get_contents($treePath))->toBe($treeBefore);
});

it('saves valid field and parent changes with the resulting URL', function () {
    collectionTreeRegressionFixture('/{parent_uri}/{title}');
    $result = (new EntriesRouter)->execute([
        'action' => 'update', 'collection' => 'tree_pages', 'id' => 'child',
        'data' => ['title' => 'new', 'parent' => 'b'],
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['entry']['parent'])->toBe('b')
        ->and($result['data']['entry']['url'])->toBe('/b/new');
    Blink::flush();
    expect(Entry::find('child')->get('title'))->toBe('new')
        ->and(Entry::findByUri('/b/new', 'default')?->id())->toBe('child')
        ->and(Entry::findByUri('/a/old', 'default'))->toBeNull();
});

it('creates a root with a null parent and refuses attempts to move it', function () {
    $collection = Collection::make('tree_root')->title('Pages')->sites(['default'])
        ->routes('/{parent_uri}/{slug}')->structureContents(['root' => true, 'max_depth' => 5]);
    $collection->save();
    Blueprint::make('page')->setNamespace('collections.tree_root')->setContents([
        'tabs' => ['main' => ['sections' => [['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
        ]]]]],
    ])->save();

    $router = new EntriesRouter;
    $root = $router->execute([
        'action' => 'create', 'collection' => 'tree_root', 'slug' => 'home',
        'data' => ['title' => 'Home', 'parent' => null],
    ]);
    expect($root['success'])->toBeTrue()
        ->and($root['data']['entry']['parent'])->toBeNull()
        ->and($root['data']['entry']['url'])->toBe('/');
    $rootId = $root['data']['entry']['id'];

    $child = $router->execute([
        'action' => 'create', 'collection' => 'tree_root', 'slug' => 'child',
        'data' => ['title' => 'Child', 'parent' => $rootId],
    ]);
    expect($child['success'])->toBeTrue()
        ->and($child['data']['entry']['parent'])->toBeNull();

    $treePath = Entry::find($rootId)->collection()->structure()->in('default')->path();
    $before = file_get_contents($treePath);
    $unchanged = $router->execute([
        'action' => 'update', 'collection' => 'tree_root', 'id' => $rootId,
        'data' => ['parent' => null],
    ]);
    expect($unchanged['success'])->toBeTrue()
        ->and(file_get_contents($treePath))->toBe($before);

    foreach ([$rootId, $child['data']['entry']['id']] as $parent) {
        $result = $router->execute([
            'action' => 'update', 'collection' => 'tree_root', 'id' => $rootId,
            'data' => ['parent' => $parent],
        ]);
        expect($result['success'])->toBeFalse()
            ->and(file_get_contents($treePath))->toBe($before);
    }
});

it('refuses invalid branch moves before saving entry fields', function (string $parent, int $maxDepth, string $error) {
    collectionTreeRegressionFixture();
    $collection = Collection::find('tree_pages');
    $collection->structure()->maxDepth($maxDepth);
    $collection->save();
    Blink::flush();
    $entry = Entry::find('a');
    $entryPath = $entry->path();
    $treePath = $collection->structure()->in('default')->path();
    $entryBefore = file_get_contents($entryPath);
    $treeBefore = file_get_contents($treePath);

    $result = (new EntriesRouter)->execute([
        'action' => 'update', 'collection' => 'tree_pages', 'id' => 'a',
        'data' => ['title' => 'Must not be saved', 'parent' => $parent],
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['errors'][0])->toContain($error)
        ->and(file_get_contents($entryPath))->toBe($entryBefore)
        ->and(file_get_contents($treePath))->toBe($treeBefore);
})->with([
    'cycle through a descendant' => ['child', 5, 'descendant'],
    'descendant exceeds maximum depth' => ['b', 2, 'maximum depth'],
]);

it('rejects a descendant URI collision with another collection without changing files', function () {
    collectionTreeRegressionFixture();
    Collection::make('external')->sites(['default'])->routes('/b/a/{slug}')->save();
    Entry::make()->id('external-child')->collection('external')->locale('default')
        ->slug('child')->data(['title' => 'External'])->save();
    Blink::flush();
    expect(Entry::findByUri('/b/a/child', 'default')?->id())->toBe('external-child');

    $entry = Entry::find('a');
    $entryPath = $entry->path();
    $treePath = $entry->collection()->structure()->in('default')->path();
    $entryBefore = file_get_contents($entryPath);
    $treeBefore = file_get_contents($treePath);
    $result = (new EntriesRouter)->execute([
        'action' => 'update', 'collection' => 'tree_pages', 'id' => 'a',
        'data' => ['title' => 'Must not be saved', 'parent' => 'b'],
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['errors'][0])->toContain('another collection URI')
        ->and(file_get_contents($entryPath))->toBe($entryBefore)
        ->and(file_get_contents($treePath))->toBe($treeBefore);
});

it('moves a published entry without publishing or discarding its working copy', function () {
    collectionTreeRegressionFixture();
    config(['statamic.revisions.enabled' => true, 'statamic.editions.pro' => true]);
    Collection::find('tree_pages')->revisionsEnabled(true)->save();
    Blink::flush();
    $entry = Entry::find('child')->published(true);
    $entry->save();
    $path = $entry->path();
    $before = file_get_contents($path);
    $router = new EntriesRouter;
    $draft = $router->execute([
        'action' => 'update', 'collection' => 'tree_pages', 'id' => 'child',
        'data' => ['title' => 'Unpublished change'],
    ]);
    expect($draft['success'])->toBeTrue();

    $move = $router->execute([
        'action' => 'update', 'collection' => 'tree_pages', 'id' => 'child',
        'data' => ['parent' => 'b'],
    ]);
    expect($move['success'])->toBeTrue()
        ->and($move['data']['entry']['parent'])->toBe('b')
        ->and(file_get_contents($path))->toBe($before);

    $workingCopy = $router->execute([
        'action' => 'get', 'collection' => 'tree_pages', 'id' => 'child',
        'version' => 'working_copy',
    ]);
    expect($workingCopy['success'])->toBeTrue()
        ->and($workingCopy['data']['entry']['data']['title'])->toBe('Unpublished change')
        ->and($workingCopy['data']['entry']['parent'])->toBe('b');

    $treePath = Entry::find('child')->collection()->structure()->in('default')->path();
    $treeBefore = file_get_contents($treePath);
    $mixed = $router->execute([
        'action' => 'update', 'collection' => 'tree_pages', 'id' => 'child',
        'data' => ['parent' => 'a', 'title' => 'Must not be saved'],
    ]);
    expect($mixed['success'])->toBeFalse()
        ->and($mixed['errors'][0])->toContain('separately from content edits')
        ->and(file_get_contents($path))->toBe($before)
        ->and(file_get_contents($treePath))->toBe($treeBefore);
});
