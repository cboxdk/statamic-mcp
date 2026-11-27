<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\FormsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\GlobalsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\TermsRouter;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Form;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\User;

/*
 * V03 exit criterion, second half: the site a call reaches and the fields a
 * site hides, per content domain and per transport. The actor may access the
 * `en` site only and holds every content permission there; `secret_note` is a
 * denied field in all three domains.
 */

beforeEach(function () {
    config(['statamic.editions.pro' => true, 'statamic.system.multisite' => true]);
    Site::setSites([
        'en' => ['name' => 'English', 'locale' => 'en_US', 'url' => '/'],
        'de' => ['name' => 'Deutsch', 'locale' => 'de_DE', 'url' => '/de/'],
    ]);

    $fields = ['fields' => [
        ['handle' => 'title', 'field' => ['type' => 'text']],
        ['handle' => 'secret_note', 'field' => ['type' => 'text']],
    ]];

    Collection::make('mx_pages')->sites(['en', 'de'])->save();
    Blueprint::make('mx_pages')->setNamespace('collections.mx_pages')->setContents($fields)->save();
    $entry = Entry::make()->id('mx-entry')->collection('mx_pages')->locale('en')->slug('one')
        ->data(['title' => 'English', 'secret_note' => 'stored'])->published(false);
    $entry->save();
    $entry->makeLocalization('de')->id('mx-entry-de')->data(['title' => 'Deutsch'])->save();

    Taxonomy::make('mx_tags')->sites(['en', 'de'])->save();
    Blueprint::make('mx_tags')->setNamespace('taxonomies.mx_tags')->setContents($fields)->save();
    $term = Term::make()->taxonomy('mx_tags')->slug('one');
    $term->in('en')->data(['title' => 'English', 'secret_note' => 'stored']);
    $term->in('de')->data(['title' => 'Deutsch']);
    $term->save();

    $set = GlobalSet::make('mx_settings')->title('Settings')->sites(['en', 'de']);
    $set->save();
    $set->makeLocalization('en')->data(['title' => 'English', 'secret_note' => 'stored'])->save();
    $set->makeLocalization('de')->data(['title' => 'Deutsch'])->save();
    Blueprint::make('mx_settings')->setNamespace('globals')->setContents($fields)->save();

    Role::make()->handle('mx_editor')->permissions([
        'access cp', 'access en site',
        'view mx_pages entries', 'edit mx_pages entries', 'create mx_pages entries',
        'view mx_tags terms', 'edit mx_tags terms', 'create mx_tags terms',
        'edit mx_settings globals',
    ])->save();
    $user = User::make()->id('mx-editor')->email('mx@example.test')->assignRole('mx_editor');
    $user->save();
    $this->actingAs($user);
});

function mxCall(string $domain, string $action, string $site, array $data = []): array
{
    // Each action gets only what it reads: v4 refuses the rest.
    $write = in_array($action, ['create', 'update'], true) ? ['data' => $data ?: ['title' => 'Edited']] : [];

    return match ($domain) {
        'entries' => (new EntriesRouter)->execute(['action' => $action, 'collection' => 'mx_pages', 'site' => $site]
            + ($action === 'create' ? ['data' => ['title' => 'New ' . $site, 'published' => false] + $data] : ['id' => 'mx-entry'] + $write)),
        'terms' => (new TermsRouter)->execute(['action' => $action, 'taxonomy' => 'mx_tags', 'site' => $site]
            + match ($action) {
                'create' => ['slug' => 'new-' . $site, 'data' => ['title' => 'New'] + $data],
                'list' => [],
                default => ['slug' => 'one'] + $write,
            }),
        'globals' => (new GlobalsRouter)->execute(['action' => $action, 'handle' => 'mx_settings', 'site' => $site] + $write),
    };
}

function mxWeb(bool $web): void
{
    config(['statamic.mcp.security.force_web_mode' => $web]);
}

it('keeps every content domain to the sites the actor may access over HTTP', function (string $domain, string $action) {
    mxWeb(true);

    $allowed = mxCall($domain, $action, 'en');
    $denied = mxCall($domain, $action, 'de');

    expect($allowed['success'])->toBeTrue(json_encode($allowed))
        ->and($denied['success'])->toBeFalse(json_encode($denied))
        ->and(implode(' ', $denied['errors']))->toContain('Permission denied');
})->with([
    'entries get' => ['entries', 'get'],
    'entries update' => ['entries', 'update'],
    'entries create' => ['entries', 'create'],
    'terms list' => ['terms', 'list'],
    'terms get' => ['terms', 'get'],
    'terms update' => ['terms', 'update'],
    'terms create' => ['terms', 'create'],
    'globals get' => ['globals', 'get'],
    'globals update' => ['globals', 'update'],
]);

it('leaves the refused site untouched', function () {
    mxWeb(true);

    mxCall('terms', 'update', 'de', ['title' => 'Forbidden']);
    mxCall('globals', 'update', 'de', ['title' => 'Forbidden']);

    expect(Term::find('mx_tags::one')->in('de')->get('title'))->toBe('Deutsch')
        ->and(GlobalSet::find('mx_settings')->in('de')->get('title'))->toBe('Deutsch')
        ->and(Term::query()->where('taxonomy', 'mx_tags')->where('slug', 'new-de')->count())->toBe(0);
});

it('lets the local operator reach every site over stdio', function (string $domain) {
    mxWeb(false);

    expect(mxCall($domain, 'get', 'de')['success'])->toBeTrue();
})->with(['entries', 'terms', 'globals']);

it('keeps localized overrides separate and hides unreadable origins and localizations', function () {
    config(['statamic.mcp.tools.entries.denied_fields' => ['secret_note']]);
    $origin = Entry::find('mx-entry');
    $origin->set('inherited', 'From English')->save();
    $origin->in('de')->set('local_only', 'Deutsch')->save();

    mxWeb(false);
    $local = mxCall('entries', 'get', 'de')['data']['entry'];
    expect($local['origin'])->toBe(['id' => 'mx-entry', 'site' => 'en'])
        ->and($local['localizations'])->toBe([['id' => 'mx-entry', 'site' => 'en']])
        ->and($local['data'])->not->toHaveKey('inherited')
        ->and($local['merged_data'])->toMatchArray(['title' => 'Deutsch', 'inherited' => 'From English', 'local_only' => 'Deutsch'])
        ->and(json_encode($local))->not->toContain('secret_note');

    mxWeb(true);
    expect(mxCall('entries', 'get', 'en')['data']['entry']['localizations'])->toBe([]);
    $role = Role::find('mx_editor');
    $role->permissions(['access cp', 'access de site', 'view mx_pages entries'])->save();
    $local = (new EntriesRouter)->execute(['action' => 'get', 'collection' => 'mx_pages', 'site' => 'de', 'slug' => 'one']);
    expect($local['success'])->toBeTrue(json_encode($local));
    expect($local['data']['entry']['origin'])->toBeNull()
        ->and($local['data']['entry']['localizations'])->toBe([])
        ->and($local['data']['entry']['merged_data'])->not->toHaveKey('inherited');
});

it('does not replay localization metadata after losing access to the related site', function () {
    mxWeb(true);
    $role = Role::find('mx_editor');
    $permissions = [...$role->permissions()->all(), 'access de site'];
    $role->permissions($permissions)->save();
    $arguments = ['action' => 'update', 'collection' => 'mx_pages', 'id' => 'mx-entry-de', 'site' => 'de',
        'data' => ['title' => 'Updated'], 'idempotency_key' => 'localization-replay'];
    $saved = (new EntriesRouter)->execute($arguments);
    expect($saved['success'])->toBeTrue(json_encode($saved));
    expect($saved['data']['entry']['origin'])->toBe(['id' => 'mx-entry', 'site' => 'en'])
        ->and($saved['data']['entry'])->not->toHaveKey('merged_data');

    $role->permissions(array_values(array_diff($permissions, ['access en site'])))->save();
    $replayed = (new EntriesRouter)->execute($arguments);
    expect($replayed['success'])->toBeTrue(json_encode($replayed));
    expect($replayed['data']['entry']['origin'])->toBeNull()
        ->and($replayed['data']['entry']['localizations'])->toBe([]);
});

it('warns about unknown top-level fields for each content domain without storing them', function (string $domain) {
    mxWeb(false);
    $result = mxCall($domain, 'update', 'en', ['title' => 'Updated', 'unknown_field' => 'private value']);
    expect($result['success'])->toBeTrue(json_encode($result));
    expect(implode(' ', $result['warnings'] ?? []))->toContain('unknown_field')->not->toContain('private value');
    expect(json_encode($result['data']))->not->toContain('private value');
})->with(['entries', 'terms', 'globals']);

it('refuses stored write results after losing access to their site', function (string $domain) {
    mxWeb(true);
    $role = Role::find('mx_editor');
    $permissions = $role->permissions()->all();
    [$router, $target] = match ($domain) {
        'entries' => [new EntriesRouter, ['collection' => 'mx_pages', 'id' => 'mx-entry']],
        'terms' => [new TermsRouter, ['taxonomy' => 'mx_tags', 'slug' => 'one']],
        'globals' => [new GlobalsRouter, ['handle' => 'mx_settings']],
    };
    $arguments = $target + ['action' => 'update', 'site' => 'en', 'data' => ['title' => 'Private snapshot'], 'idempotency_key' => 'site-replay-check'];
    $first = $router->execute($arguments);
    expect($first['success'])->toBeTrue(json_encode($first));
    expect($router->execute($arguments)['success'])->toBeTrue();

    $role->permissions(array_values(array_diff($permissions, ['access en site'])))->save();
    $retry = $router->execute($arguments);
    expect($retry['success'])->toBeFalse(json_encode($retry))
        ->and(json_encode($retry))->not->toContain('Private snapshot');
})->with(['entries', 'terms', 'globals']);

it('rechecks an entry author restriction on a stored update or delete result', function (string $action) {
    mxWeb(true);
    config(['statamic.mcp.confirmation.enabled' => false]);
    Blueprint::make('mx_pages')->setNamespace('collections.mx_pages')->setContents(['fields' => [
        ['handle' => 'title', 'field' => ['type' => 'text']],
        ['handle' => 'author', 'field' => ['type' => 'users']],
    ]])->save();
    User::make()->id('other-author')->email('other-author@example.test')->save();
    Entry::find('mx-entry')->set('author', ['other-author'])->save();
    if ($action === 'delete') {
        Entry::find('mx-entry-de')->delete();
    }
    $role = Role::find('mx_editor');
    $permissions = [...$role->permissions()->all(), 'delete mx_pages entries'];
    // The native update policy calls this ability edit.
    $otherAuthorPermission = $action === 'update' ? 'edit other authors mx_pages entries' : 'delete other authors mx_pages entries';
    $role->permissions([...$permissions, $otherAuthorPermission])->save();
    $arguments = ['action' => $action, 'collection' => 'mx_pages', 'id' => 'mx-entry', 'idempotency_key' => 'author-replay-check']
        + ($action === 'update' ? ['data' => ['title' => 'Private author snapshot']] : []);
    $router = new EntriesRouter;
    $first = $router->execute($arguments);
    expect($first['success'])->toBeTrue(json_encode($first))
        ->and($router->execute($arguments)['success'])->toBeTrue();
    $role->permissions(array_values(array_diff($permissions, [$otherAuthorPermission])))->save();
    $retry = $router->execute($arguments);
    expect($retry['success'])->toBeFalse(json_encode($retry))
        ->and(json_encode($retry))->not->toContain('Private author snapshot');
})->with(['update', 'delete']);

it('lets a create-only actor replay their successful create without granting edit access', function () {
    mxWeb(true);
    Role::find('mx_editor')->permissions(['access cp', 'access en site', 'create mx_pages entries'])->save();
    $arguments = ['action' => 'create', 'collection' => 'mx_pages', 'site' => 'en', 'data' => ['title' => 'Created', 'published' => false], 'idempotency_key' => 'create-only-replay'];
    $router = new EntriesRouter;
    $first = $router->execute($arguments);
    expect($first['success'])->toBeTrue(json_encode($first));
    $retry = $router->execute($arguments);
    expect($retry['success'])->toBeTrue(json_encode($retry))
        ->and($retry['data']['entry']['id'])->toBe($first['data']['entry']['id']);
});

it('authorizes every navigation tree before saving any settings or trees', function (bool $hideHandle) {
    mxWeb(true);
    config(['statamic.mcp.tools.structures.denied_fields' => $hideHandle ? ['handle'] : []]);
    $role = Role::find('mx_editor');
    $role->permissions([...$role->permissions()->all(), 'view main nav', 'edit main nav'])->save();
    $nav = Nav::make('main')->title('Main');
    $nav->save();
    $nav->makeTree('en', [['id' => 'en-link', 'title' => 'English', 'url' => '/']])->save();
    $nav->makeTree('de', [['id' => 'de-link', 'title' => 'Private German', 'url' => '/de/']])->save();
    $router = new StructuresRouter;
    $target = ['resource_type' => 'navigation', 'handle' => 'main'];
    $denied = $router->execute($target + ['action' => 'update', 'data' => [
        'title' => 'Forbidden change', 'trees' => [['site' => 'en', 'items' => []], ['site' => 'de', 'items' => []]],
    ]]);
    expect($denied['success'])->toBeFalse(json_encode($denied))
        ->and(Nav::find('main')->title())->toBe('Main')
        ->and(Nav::find('main')->in('en')->tree())->toHaveCount(1)
        ->and(Nav::find('main')->in('de')->tree())->toHaveCount(1);

    $read = $router->execute($target + ['action' => 'get']);
    expect($read['success'])->toBeTrue(json_encode($read))
        ->and(array_column($read['data']['navigation']['trees'], 'site'))->toBe(['en']);
    $arguments = $target + ['action' => 'update', 'data' => ['trees' => [['site' => 'en', 'items' => []]]], 'idempotency_key' => 'nav-site-replay-check'];
    $allowed = $router->execute($arguments);
    expect($allowed['success'])->toBeTrue(json_encode($allowed))
        ->and(json_encode($allowed))->not->toContain('Private German');
    $role->permissions(['access cp', 'view main nav', 'edit main nav'])->save();
    $retry = $router->execute($arguments);
    expect($retry['data']['navigation']['trees'] ?? [])->toBe([]);
})->with([false, true]);

it('replays deletion of an owned localization without exposing inherited authors', function () {
    mxWeb(true);
    config(['statamic.mcp.confirmation.enabled' => false, 'statamic.mcp.tools.entries.denied_fields' => ['author', 'origin']]);
    Blueprint::make('mx_pages')->setNamespace('collections.mx_pages')->setContents(['fields' => [
        ['handle' => 'title', 'field' => ['type' => 'text']],
        ['handle' => 'author', 'field' => ['type' => 'users']],
    ]])->save();
    Entry::find('mx-entry')->set('author', ['mx-editor'])->save();
    Role::find('mx_editor')->permissions(['access cp', 'access de site', 'delete mx_pages entries'])->save();
    $router = new EntriesRouter;
    $arguments = ['action' => 'delete', 'collection' => 'mx_pages', 'id' => 'mx-entry-de', 'idempotency_key' => 'inherited-author-delete'];
    $first = $router->execute($arguments);
    $retry = $router->execute($arguments);
    expect($first['success'])->toBeTrue(json_encode($first))
        ->and($retry['success'])->toBeTrue(json_encode($retry))
        ->and(json_encode([$first, $retry]))->not->toContain('mx-editor')->not->toContain('_deleted_authors');
    Role::find('mx_editor')->permissions(['access cp', 'access de site'])->save();
    expect($router->execute($arguments)['success'])->toBeFalse();
});

it('hides a denied field from output and refuses to write it, on every transport', function (string $domain, bool $web) {
    mxWeb($web);
    config(["statamic.mcp.tools.{$domain}.denied_fields" => ['secret_note']]);

    $read = mxCall($domain, 'get', 'en');
    expect($read['success'])->toBeTrue(json_encode($read))
        ->and(json_encode($read['data']))->not->toContain('secret_note')->not->toContain('stored');

    $write = mxCall($domain, 'update', 'en', ['title' => 'Edited', 'secret_note' => 'overwritten']);
    expect($write['success'])->toBeTrue(json_encode($write));

    $stored = match ($domain) {
        'entries' => Entry::find('mx-entry')->get('secret_note'),
        'terms' => Term::find('mx_tags::one')->in('en')->get('secret_note'),
        'globals' => GlobalSet::find('mx_settings')->in('en')->get('secret_note'),
    };
    expect($stored)->toBe('stored');
})->with([
    'entries http' => ['entries', true],
    'entries stdio' => ['entries', false],
    'terms http' => ['terms', true],
    'terms stdio' => ['terms', false],
    'globals http' => ['globals', true],
    'globals stdio' => ['globals', false],
]);

it('does not let a list filter probe a denied field', function () {
    mxWeb(true);
    config(['statamic.mcp.tools.entries.denied_fields' => ['secret_note']]);

    // A filter on the hidden field is dropped: a match on "wrong" would
    // otherwise reveal that the stored value is something else.
    $result = (new EntriesRouter)->execute(['action' => 'list', 'collection' => 'mx_pages', 'site' => 'en',
        'include_unpublished' => true, 'filters' => ['secret_note' => 'wrong']]);

    expect($result['success'])->toBeTrue(json_encode($result))
        ->and(array_column($result['data']['items'], 'id'))->toContain('mx-entry');
});

it('binds submission reads and writes to the site and hides denied fields on both transports', function (bool $web) {
    mxWeb($web);
    config(['statamic.mcp.tools.forms.denied_fields' => ['secret_note']]);
    $role = Role::find('mx_editor');
    $role->permissions([...$role->permissions()->all(), 'view contact form submissions', 'manage contact form spam']);
    $role->save();
    $form = Form::make('contact')->title('Contact');
    $form->save();
    foreach (['en' => '1700000001.100', 'de' => '1700000002.100'] as $site => $id) {
        $form->makeSubmission()->id($id)->site($site)->data(['title' => $site, 'secret_note' => 'stored'])->save();
    }
    $router = new FormsRouter;
    $read = $router->execute(['action' => 'get_submission', 'form' => 'contact', 'site' => 'en', 'id' => '1700000001.100']);
    expect($read['success'])->toBeTrue(json_encode($read))
        ->and(json_encode($read['data']))->not->toContain('secret_note')->not->toContain('stored');
    $other = $router->execute(['action' => 'mark_spam', 'form' => 'contact', 'site' => 'de', 'id' => '1700000002.100']);
    expect($other['success'])->toBe(! $web, json_encode($other))
        ->and($form->submission('1700000002.100')->status())->toBe($web ? 'finalized' : 'spam');
})->with(['http' => true, 'stdio' => false]);
