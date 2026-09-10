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
 * `template` and `layout` are entry data, but they are not blueprint fields.
 *
 * Entry::template() and Entry::layout() both fall back to `$this->get(...)`,
 * so choosing a template per entry is ordinary Statamic usage. Most blueprints
 * do not declare a field for it, and the write pipeline runs values through
 * Fields::addValues()->process()->values(), which only knows blueprint handles
 * — so before this these keys were dropped and the write reported success
 * having changed nothing.
 */
class EntriesTemplateLayoutTest extends TestCase
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

        Collection::make($this->collectionHandle)->title('Pages')->sites(['nl', 'en'])->save();

        Blueprint::make('pages')->setNamespace("collections.{$this->collectionHandle}")->setContents([
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
            ],
        ])->save();

        Stache::refresh();

        Entry::make()
            ->id('home')
            ->collection($this->collectionHandle)
            ->locale('nl')
            ->slug('home')
            ->data(['title' => 'Home'])
            ->save();
    }

    public function test_update_persists_template_and_layout(): void
    {
        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => 'home',
            'data' => ['title' => 'Home', 'template' => 'pages/landing', 'layout' => 'layouts/wide'],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));

        $entry = Entry::find('home');
        $this->assertSame('pages/landing', $entry->template());
        $this->assertSame('layouts/wide', $entry->layout());
    }

    public function test_create_persists_template(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'slug' => 'about',
            'data' => ['title' => 'About', 'template' => 'pages/about'],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame('pages/about', Entry::find($result['data']['entry']['id'])->template());
    }

    public function test_a_localization_can_carry_its_own_template(): void
    {
        $result = $this->router->execute([
            'action' => 'localize',
            'collection' => $this->collectionHandle,
            'id' => 'home',
            'site' => 'en',
            'data' => ['title' => 'Home', 'template' => 'pages/landing-en'],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame('pages/landing-en', Entry::find('home')->in('en')->template());
    }

    public function test_update_can_clear_the_template_again(): void
    {
        Entry::find('home')->merge(['template' => 'pages/landing'])->save();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => 'home',
            'data' => ['template' => null],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        // Falls back to the collection's template once the entry's own is gone.
        $this->assertNotSame('pages/landing', Entry::find('home')->template());
    }

    public function test_a_non_string_template_is_refused(): void
    {
        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => 'home',
            'data' => ['template' => ['pages/landing']],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('[template]', implode(' ', $result['errors']));
    }

    public function test_a_blueprint_that_declares_template_still_goes_through_the_normal_pipeline(): void
    {
        $handle = 'pages-declared-' . bin2hex(random_bytes(4));
        Collection::make($handle)->title('Declared')->save();
        Blueprint::make('pages')->setNamespace("collections.{$handle}")->setContents([
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'template', 'field' => ['type' => 'template']],
            ],
        ])->save();
        Stache::refresh();

        Entry::make()->id('declared')->collection($handle)->slug('declared')->data(['title' => 'D'])->save();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $handle,
            'id' => 'declared',
            'data' => ['template' => 'pages/declared'],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame('pages/declared', Entry::find('declared')->template());
    }

    public function test_parent_is_not_treated_as_writable_entry_data(): void
    {
        // Entry::parent() derives from the structure tree, so storing a parent
        // key would be inert. It stays dropped rather than being passed through
        // and giving the caller the impression the entry was moved.
        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => 'home',
            'data' => ['title' => 'Home', 'parent' => 'some-other-entry'],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertNull(Entry::find('home')->get('parent'));
    }
}
