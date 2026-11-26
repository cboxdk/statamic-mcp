<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\GlobalsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\TermsRouter;
use Statamic\Facades\Blueprint;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

beforeEach(function () {
    config(['statamic.editions.pro' => true, 'statamic.system.multisite' => true]);
    Site::setSites([
        'en' => ['name' => 'English', 'locale' => 'en_US', 'url' => '/'],
        'de' => ['name' => 'Deutsch', 'locale' => 'de_DE', 'url' => '/de/'],
    ]);
    $fields = ['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]];

    Taxonomy::make('vt_tags')->sites(['en', 'de'])->save();
    Blueprint::make('vt_tags')->setNamespace('taxonomies.vt_tags')->setContents($fields)->save();
    $term = Term::make()->taxonomy('vt_tags')->slug('one');
    $term->in('en')->data(['title' => 'English']);
    $term->in('de')->data(['title' => 'Deutsch']);
    $term->save();

    $set = GlobalSet::make('vt_settings')->title('Settings')->sites(['en', 'de']);
    $set->save();
    $set->makeLocalization('en')->data(['title' => 'English'])->save();
    $set->makeLocalization('de')->data(['title' => 'Deutsch'])->save();
    Blueprint::make('vt_settings')->setNamespace('globals')->setContents($fields)->save();
});

function vtTerm(array $arguments): array
{
    return (new TermsRouter)->execute(['taxonomy' => 'vt_tags', 'site' => 'en'] + $arguments);
}

function vtGlobal(array $arguments): array
{
    return (new GlobalsRouter)->execute(['handle' => 'vt_settings', 'site' => 'en'] + $arguments);
}

function vtStoredTitle(string $site): mixed
{
    return Term::find('vt_tags::one')?->in($site)?->get('title');
}

it('reports a term version that changes with a write, whichever way the term is named', function () {
    $bySlug = vtTerm(['action' => 'get', 'slug' => 'one'])['data']['term']['version'];

    expect(vtTerm(['action' => 'get', 'id' => 'vt_tags::one'])['data']['term']['version'])->toBe($bySlug);

    $updated = vtTerm(['action' => 'update', 'id' => 'vt_tags::one', 'expected_version' => $bySlug, 'data' => ['title' => 'Edited']]);

    expect($updated['success'])->toBeTrue(json_encode($updated))
        ->and($updated['data']['term']['version'])->toBeString()->not->toBe($bySlug)
        ->and(vtStoredTitle('en'))->toBe('Edited');
});

it('refuses a stale term version on update and delete without saving', function () {
    $version = vtTerm(['action' => 'get', 'slug' => 'one'])['data']['term']['version'];

    // A Control Panel save in the other site: the term is one file, so the
    // version covers it.
    Term::find('vt_tags::one')->in('de')->set('title', 'Changed in the CP')->save();

    foreach (['update', 'delete'] as $action) {
        $result = vtTerm(['action' => $action, 'slug' => 'one', 'expected_version' => $version, ...($action === 'update' ? ['data' => ['title' => 'Overwrite']] : [])]);
        expect($result['success'])->toBeFalse()
            ->and($result['data']['code'])->toBe('VERSION_CONFLICT')
            ->and(implode(' ', $result['errors']))->toContain('the term changed');
    }

    expect(vtStoredTitle('en'))->toBe('English')
        ->and(vtStoredTitle('de'))->toBe('Changed in the CP');
});

it('lets only the first of two term writers with the same version through', function () {
    $version = vtTerm(['action' => 'get', 'slug' => 'one'])['data']['term']['version'];

    $first = vtTerm(['action' => 'update', 'slug' => 'one', 'expected_version' => $version, 'data' => ['title' => 'First']]);
    $second = vtTerm(['action' => 'update', 'slug' => 'one', 'expected_version' => $version, 'data' => ['title' => 'Second']]);

    expect($first['success'])->toBeTrue(json_encode($first))
        ->and($second['success'])->toBeFalse()
        ->and($second['data']['current_version'])->toBe($first['data']['term']['version'])
        ->and(vtStoredTitle('en'))->toBe('First');
});

it('checks a global set version per site', function () {
    $en = vtGlobal(['action' => 'get'])['data']['global']['version'];

    // Another site's values live in their own file and do not make en stale.
    GlobalSet::find('vt_settings')->in('de')->set('title', 'Changed in de')->save();
    $updated = vtGlobal(['action' => 'update', 'expected_version' => $en, 'data' => ['title' => 'First']]);

    expect($updated['success'])->toBeTrue(json_encode($updated))
        ->and($updated['data']['global']['version'])->toBeString()->not->toBe($en);

    $stale = vtGlobal(['action' => 'update', 'expected_version' => $en, 'data' => ['title' => 'Second']]);

    expect($stale['success'])->toBeFalse()
        ->and($stale['data']['code'])->toBe('VERSION_CONFLICT')
        ->and($stale['data']['current_version'])->toBe($updated['data']['global']['version'])
        ->and(GlobalSet::find('vt_settings')->in('en')->get('title'))->toBe('First');
});

it('refuses an empty expected version instead of writing unconditionally', function () {
    $term = vtTerm(['action' => 'update', 'slug' => 'one', 'expected_version' => '', 'data' => ['title' => 'Edited']]);
    $global = vtGlobal(['action' => 'update', 'expected_version' => '', 'data' => ['title' => 'Edited']]);

    expect($term['success'])->toBeFalse()
        ->and($global['success'])->toBeFalse()
        ->and(vtStoredTitle('en'))->toBe('English')
        ->and(GlobalSet::find('vt_settings')->in('en')->get('title'))->toBe('English');
});
