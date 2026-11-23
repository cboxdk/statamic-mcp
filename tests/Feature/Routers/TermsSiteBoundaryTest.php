<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\TermsRouter;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\User;

/*
 * Statamic's TermPolicy checks the site only on create, and the Control Panel
 * relies on its site selector for the rest. MCP has no selector, so a user
 * limited to one site must not reach another site's terms through it.
 */

beforeEach(function () {
    config(['statamic.editions.pro' => true, 'statamic.system.multisite' => true, 'statamic.mcp.security.force_web_mode' => true]);
    Site::setSites([
        'en' => ['name' => 'English', 'locale' => 'en_US', 'url' => '/'],
        'de' => ['name' => 'Deutsch', 'locale' => 'de_DE', 'url' => '/de/'],
    ]);

    Taxonomy::make('site_tags')->sites(['en', 'de'])->save();
    Blueprint::make('site_tags')->setNamespace('taxonomies.site_tags')->setContents(['fields' => [
        ['handle' => 'title', 'field' => ['type' => 'text']],
    ]])->save();
    $term = Term::make()->taxonomy('site_tags')->slug('one');
    $term->in('en')->data(['title' => 'English']);
    $term->in('de')->data(['title' => 'Deutsch']);
    $term->save();

    Role::make()->handle('site_tags_editor')->permissions([
        'access cp', 'access en site', 'view site_tags terms', 'edit site_tags terms', 'create site_tags terms',
    ])->save();
    $user = User::make()->id('site-tags-editor')->email('site-tags@example.test')->assignRole('site_tags_editor');
    $user->save();
    $this->actingAs($user);
});

function termsIn(string $action, string $site): array
{
    return (new TermsRouter)->execute(['action' => $action, 'taxonomy' => 'site_tags', 'site' => $site]
        + ($action === 'create' ? ['slug' => 'new-' . $site, 'data' => ['title' => 'New']] : ['slug' => 'one', 'data' => ['title' => 'Edited']]));
}

it('allows term actions in the users site and refuses them in another', function (string $action) {
    expect(termsIn($action, 'en')['success'])->toBeTrue();

    $denied = termsIn($action, 'de');
    expect($denied['success'])->toBeFalse()
        ->and(implode(' ', $denied['errors']))->toContain('Permission denied: Cannot access this site');
})->with(['list', 'get', 'update', 'create']);

it('leaves the other sites terms untouched', function () {
    termsIn('update', 'de');
    termsIn('create', 'de');

    expect(Term::find('site_tags::one')->in('de')->get('title'))->toBe('Deutsch')
        ->and(Term::query()->where('taxonomy', 'site_tags')->where('slug', 'new-de')->count())->toBe(0);
});

it('still lets the local operator reach every site', function () {
    config(['statamic.mcp.security.force_web_mode' => false]);

    expect(termsIn('get', 'de')['success'])->toBeTrue();
});
