<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Cache;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\GlobalsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\TermsRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\StaticCaching\Cacher;

/**
 * The V08 cache matrix, measured on rendered pages rather than on which
 * commands ran: each write is followed by reading every affected URL with
 * the static cache warm, and again with the Stache and static cache cold.
 *
 * The site: a layout showing a global and a navigation, a home page listing
 * the blog, blog posts showing their tags, and the REST API.
 */
class RenderedCacheMatrixTest extends TestCase
{
    /** @var array<string, string> */
    private array $ids = [];

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('statamic.static_caching.strategy', 'half');
        $app['config']->set('statamic.api.enabled', true);
        $app['config']->set('statamic.api.resources.collections', true);
        $app['config']->set('statamic.editions.pro', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['statamic.revisions.enabled' => false]);

        $views = sys_get_temp_dir() . '/mcp-cache-matrix-views';
        @mkdir($views);
        file_put_contents("{$views}/layout.antlers.html", 'TAGLINE={{ settings:tagline }}|NAV={{ nav:main }}{{ title }},{{ /nav:main }}|{{ template_content }}');
        file_put_contents("{$views}/home.antlers.html", 'HOME={{ collection:blog }}{{ title }}^{{ url }};{{ /collection:blog }}');
        file_put_contents("{$views}/post.antlers.html", 'POST={{ title }}|TAGS={{ tags }}{{ title }},{{ /tags }}');
        View::addLocation($views);

        Taxonomy::make('tags')->save();
        Blueprint::make('tag')->setNamespace('taxonomies.tags')->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]])->save();
        Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP'])->save();

        Collection::make('pages')->routes('/{slug}')->template('home')->save();
        Collection::make('blog')->routes('/blog/{slug}')->template('post')->taxonomies(['tags'])->save();
        Blueprint::make('post')->setNamespace('collections.blog')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'tags', 'field' => ['type' => 'terms', 'taxonomies' => ['tags']]],
        ]])->save();
        Blueprint::make('page')->setNamespace('collections.pages')->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]])->save();

        $home = Entry::make()->collection('pages')->slug('home')->data(['title' => 'Home']);
        $home->save();
        $this->ids['home'] = (string) $home->id();
        foreach (['post-a' => 'Post A', 'post-b' => 'Post B'] as $slug => $title) {
            $post = Entry::make()->collection('blog')->slug($slug)->data(['title' => $title, 'tags' => ['php']]);
            $post->save();
            $this->ids[$slug] = (string) $post->id();
        }
        $draft = Entry::make()->collection('blog')->slug('draft')->published(false)->data(['title' => 'Draft']);
        $draft->save();
        $this->ids['draft'] = (string) $draft->id();

        $settings = GlobalSet::make('settings')->title('Settings');
        $settings->save();
        $settings->makeLocalization('default')->data(['tagline' => 'Old tagline'])->save();
        Blueprint::make('settings')->setNamespace('globals')->setContents(['fields' => [['handle' => 'tagline', 'field' => ['type' => 'text']]]])->save();

        $nav = Nav::make('main')->title('Main')->collections(['pages', 'blog'])->maxDepth(1);
        $nav->save();
        $nav->makeTree('default', [['entry' => $this->ids['post-a']]])->save();
    }

    /**
     * @return array<string, array{0: callable(self): array<string, mixed>, 1: array<string, list<string>|int>}>
     *
     * Expectations per URL: an int is the status, a list is text the page must
     * contain, and text prefixed with "!" must be absent.
     */
    public static function writes(): array
    {
        return [
            'entry title' => [
                fn (self $t) => (new EntriesRouter)->execute(['action' => 'update', 'collection' => 'blog', 'id' => $t->ids['post-a'], 'data' => ['title' => 'Post A2']]),
                ['/blog/post-a' => ['POST=Post A2'], '/home' => ['Post A2^', '!Post A^'], '/api/collections/blog/entries' => ['Post A2']],
            ],
            'entry slug' => [
                fn (self $t) => (new EntriesRouter)->execute(['action' => 'update', 'collection' => 'blog', 'id' => $t->ids['post-a'], 'data' => ['slug' => 'moved']]),
                ['/blog/post-a' => 404, '/blog/moved' => ['POST=Post A'], '/home' => ['^/blog/moved;', '!^/blog/post-a;']],
            ],
            'entry unpublish' => [
                fn (self $t) => (new EntriesRouter)->execute(['action' => 'unpublish', 'collection' => 'blog', 'id' => $t->ids['post-a']]),
                ['/blog/post-a' => 404, '/home' => ['!Post A^'], '/api/collections/blog/entries' => ['!Post A"']],
            ],
            'entry publish' => [
                fn (self $t) => (new EntriesRouter)->execute(['action' => 'publish', 'collection' => 'blog', 'id' => $t->ids['draft']]),
                ['/blog/draft' => ['POST=Draft'], '/home' => ['Draft^']],
            ],
            'entry delete' => [
                fn (self $t) => (new EntriesRouter)->execute(['action' => 'delete', 'collection' => 'blog', 'id' => $t->ids['post-b']]),
                ['/blog/post-b' => 404, '/home' => ['!Post B^']],
            ],
            'collection route' => [
                fn (self $t) => (new StructuresRouter)->execute(['action' => 'update', 'resource_type' => 'collection', 'handle' => 'blog', 'data' => ['route' => '/news/{slug}']]),
                ['/blog/post-a' => 404, '/news/post-a' => ['POST=Post A'], '/home' => ['^/news/post-a;']],
            ],
            'term title (dependent page)' => [
                fn (self $t) => (new TermsRouter)->execute(['action' => 'update', 'taxonomy' => 'tags', 'slug' => 'php', 'data' => ['title' => 'Modern PHP']]),
                ['/blog/post-a' => ['TAGS=Modern PHP,'], '/blog/post-b' => ['TAGS=Modern PHP,']],
            ],
            'global value (every page)' => [
                fn (self $t) => (new GlobalsRouter)->execute(['action' => 'update', 'handle' => 'settings', 'data' => ['tagline' => 'New tagline']]),
                ['/home' => ['TAGLINE=New tagline'], '/blog/post-b' => ['TAGLINE=New tagline']],
            ],
            'navigation tree (every page)' => [
                fn (self $t) => (new StructuresRouter)->execute(['action' => 'update', 'resource_type' => 'navigation', 'handle' => 'main',
                    'data' => ['trees' => [['site' => 'default', 'items' => [['entry' => $t->ids['post-b']]]]]]]),
                ['/home' => ['NAV=Post B,|'], '/blog/post-a' => ['NAV=Post B,|']],
            ],
        ];
    }

    /**
     * @param  callable(self): array<string, mixed>  $write
     * @param  array<string, list<string>|int>  $expected
     */
    #[DataProvider('writes')]
    public function test_a_write_reaches_every_affected_page_warm_and_cold(callable $write, array $expected): void
    {
        $urls = array_unique([...array_keys($expected), '/home', '/blog/post-a', '/blog/post-b', '/blog/draft']);
        foreach ($urls as $url) {
            $this->get($url);
        }
        $this->assertTrue(app(Cacher::class)->hasCachedPage(Request::create('http://localhost/blog/post-b')), 'The static cache must be warm before the write.');

        $result = $write($this);
        $this->assertTrue($result['success'] ?? false, json_encode($result) ?: '');

        $this->assertPages($expected, 'warm');

        Stache::clear();
        app(Cacher::class)->flush();
        $this->assertPages($expected, 'cold');
    }

    /** @param  array<string, list<string>|int>  $expected */
    private function assertPages(array $expected, string $cache): void
    {
        foreach ($expected as $url => $want) {
            $response = $this->get($url);
            if (is_int($want)) {
                $this->assertSame($want, $response->status(), "{$cache} {$url}");

                continue;
            }
            $this->assertSame(200, $response->status(), "{$cache} {$url}");
            $html = (string) $response->getContent();
            foreach ($want as $text) {
                str_starts_with($text, '!')
                    ? $this->assertStringNotContainsString(substr($text, 1), $html, "{$cache} {$url}")
                    : $this->assertStringContainsString($text, $html, "{$cache} {$url}");
            }
        }
    }
}
