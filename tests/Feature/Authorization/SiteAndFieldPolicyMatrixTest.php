<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\GlobalsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\TermsRouter;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
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
