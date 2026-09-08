<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;

/**
 * Translating a page needs a way to create an entry's localization.
 *
 * `create` with another site makes an unrelated entry with its own id, and
 * `update` correctly refuses a site the entry has no localization in, so
 * before this action the localization had to exist already — made by hand in
 * the Control Panel.
 */
class EntriesLocalizeTest extends TestCase
{
    private EntriesRouter $router;

    private string $collectionHandle;

    protected function setUp(): void
    {
        parent::setUp();

        config(['statamic.system.multisite' => true]);

        Site::setSites([
            'nl' => ['name' => 'Nederlands', 'locale' => 'nl_NL', 'url' => '/'],
            'en' => ['name' => 'English', 'locale' => 'en_US', 'url' => '/en/'],
        ]);

        $this->router = new EntriesRouter;
        $this->collectionHandle = 'pages-' . bin2hex(random_bytes(4));

        Collection::make($this->collectionHandle)
            ->title('Pages')
            ->sites(['nl', 'en'])
            ->save();

        Blueprint::make('pages')->setNamespace("collections.{$this->collectionHandle}")->setContents([
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'intro', 'field' => ['type' => 'textarea']],
                // Statamic's default blueprint marks slug required; a localize
                // that does not inject the entry's own slug fails on it (#39).
                ['handle' => 'slug', 'field' => ['type' => 'slug', 'validate' => ['required']]],
            ],
        ])->save();

        Entry::make()
            ->id('home')
            ->collection($this->collectionHandle)
            ->locale('nl')
            ->slug('thuis')
            ->data(['title' => 'Thuis', 'intro' => 'Welkom'])
            ->save();

        Stache::refresh();
    }

    public function test_creates_a_localization_with_translated_values(): void
    {
        $result = $this->router->execute([
            'action' => 'localize',
            'collection' => $this->collectionHandle,
            'id' => 'home',
            'site' => 'en',
            'data' => ['title' => 'Home', 'intro' => 'Welcome'],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));

        $localized = Entry::find('home')->in('en');
        $this->assertNotNull($localized);
        $this->assertSame('Home', $localized->get('title'));
        $this->assertSame('en', $localized->site()->handle());
        // The origin is kept, which is what makes untranslated fields fall back.
        $this->assertSame('nl', Entry::find('home')->site()->handle());
    }

    public function test_an_empty_localization_falls_back_to_the_origin(): void
    {
        $result = $this->router->execute([
            'action' => 'localize',
            'collection' => $this->collectionHandle,
            'id' => 'home',
            'site' => 'en',
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));

        $localized = Entry::find('home')->in('en');
        $this->assertNotNull($localized);
        $this->assertSame('Thuis', $localized->value('title'));
    }

    public function test_refuses_when_a_localization_already_exists(): void
    {
        $this->router->execute([
            'action' => 'localize',
            'collection' => $this->collectionHandle,
            'id' => 'home',
            'site' => 'en',
        ]);

        $result = $this->router->execute([
            'action' => 'localize',
            'collection' => $this->collectionHandle,
            'id' => 'home',
            'site' => 'en',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('already has a localization', implode(' ', $result['errors']));
    }

    public function test_refuses_the_origin_site(): void
    {
        $result = $this->router->execute([
            'action' => 'localize',
            'collection' => $this->collectionHandle,
            'id' => 'home',
            'site' => 'nl',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('already originates', implode(' ', $result['errors']));
    }

    public function test_refuses_a_site_the_collection_is_not_available_in(): void
    {
        Collection::find($this->collectionHandle)->sites(['nl'])->save();
        Stache::refresh();

        $result = $this->router->execute([
            'action' => 'localize',
            'collection' => $this->collectionHandle,
            'id' => 'home',
            'site' => 'en',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not available in site', implode(' ', $result['errors']));
    }

    public function test_reports_a_missing_entry(): void
    {
        $result = $this->router->execute([
            'action' => 'localize',
            'collection' => $this->collectionHandle,
            'id' => 'does-not-exist',
            'site' => 'en',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Entry not found', implode(' ', $result['errors']));
    }

    public function test_localizes_when_the_blueprint_requires_a_slug(): void
    {
        $result = $this->router->execute([
            'action' => 'localize',
            'collection' => $this->collectionHandle,
            'id' => 'home',
            'site' => 'en',
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame('thuis', Entry::find('home')->in('en')->slug());
    }

    public function test_update_can_edit_the_localization_afterwards(): void
    {
        $this->router->execute([
            'action' => 'localize',
            'collection' => $this->collectionHandle,
            'id' => 'home',
            'site' => 'en',
            'data' => ['title' => 'Home'],
        ]);

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => 'home',
            'site' => 'en',
            'data' => ['intro' => 'Welcome'],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame('Welcome', Entry::find('home')->in('en')->get('intro'));
        $this->assertSame('Welkom', Entry::find('home')->get('intro'));
    }
}
