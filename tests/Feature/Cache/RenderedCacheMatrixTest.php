<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Cache;

use Cboxdk\StatamicMcp\Mcp\Support\StaticCacheMaintenance;
use Cboxdk\StatamicMcp\Mcp\Support\WarmSitePage as StaticWarmJob;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\GlobalsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\SystemRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\TermsRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Statamic\Contracts\GraphQL\ResponseCache;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\StaticCaching\Cacher;
use Statamic\StaticCaching\Cachers\ApplicationCacher;
use Statamic\StaticCaching\DefaultInvalidator;
use Statamic\StaticCaching\Invalidator;
use Statamic\StaticCaching\StaticCacheManager;

/**
 * The V08 cache matrix, measured on rendered pages rather than on which
 * commands ran: each write is followed by reading every affected URL with
 * the static cache warm, and again with the Stache and static cache cold.
 *
 * The site: a layout showing a global and a navigation, a home page listing
 * the blog, blog posts showing their tags, a news collection mounted under
 * the about page, and the REST and GraphQL APIs (each with its own cache).
 */
class RenderedCacheMatrixTest extends TestCase
{
    private const GRAPHQL_QUERY = '{ entries(collection: "blog") { data { title ... on Entry_Blog_Post { tags { title } } } } }';

    /** @var array<string, string> */
    private array $ids = [];

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('statamic.static_caching.strategy', 'half');
        $app['config']->set('statamic.api.enabled', true);
        $app['config']->set('statamic.api.resources.collections', true);
        $app['config']->set('statamic.graphql.enabled', true);
        $app['config']->set('statamic.graphql.resources.collections', true);
        $app['config']->set('statamic.graphql.resources.taxonomies', true);
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
        foreach (['about' => 'About', 'company' => 'Company'] as $slug => $title) {
            $page = Entry::make()->collection('pages')->slug($slug)->data(['title' => $title]);
            $page->save();
            $this->ids[$slug] = (string) $page->id();
        }
        Collection::make('news')->routes('{mount}/{slug}')->mount($this->ids['about'])->template('post')->save();
        Blueprint::make('item')->setNamespace('collections.news')->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]])->save();
        Entry::make()->collection('news')->slug('launch')->data(['title' => 'Launch'])->save();
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
            'entry title (GraphQL)' => [
                fn (self $t) => (new EntriesRouter)->execute(['action' => 'update', 'collection' => 'blog', 'id' => $t->ids['post-a'], 'data' => ['title' => 'Post A2']]),
                ['graphql' => ['Post A2', '!"Post A"']],
            ],
            'entry unpublish (GraphQL)' => [
                fn (self $t) => (new EntriesRouter)->execute(['action' => 'unpublish', 'collection' => 'blog', 'id' => $t->ids['post-b']]),
                ['graphql' => ['Post A', '!Post B']],
            ],
            'term title (GraphQL)' => [
                fn (self $t) => (new TermsRouter)->execute(['action' => 'update', 'taxonomy' => 'tags', 'slug' => 'php', 'data' => ['title' => 'Modern PHP']]),
                ['graphql' => ['Modern PHP']],
            ],
            'collection mount' => [
                fn (self $t) => (new StructuresRouter)->execute(['action' => 'update', 'resource_type' => 'collection', 'handle' => 'news', 'data' => ['mount' => $t->ids['company']]]),
                ['/about/launch' => 404, '/company/launch' => ['POST=Launch']],
            ],
            'mounted page slug' => [
                fn (self $t) => (new EntriesRouter)->execute(['action' => 'update', 'collection' => 'pages', 'id' => $t->ids['about'], 'data' => ['slug' => 'about-us']]),
                ['/about/launch' => 404, '/about-us/launch' => ['POST=Launch'], '/about' => 404],
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
        $urls = array_unique([...array_keys($expected), '/home', '/blog/post-a', '/blog/post-b', '/blog/draft', '/about/launch', 'graphql']);
        foreach ($urls as $url) {
            $this->fetch($url);
        }
        $this->assertTrue(app(Cacher::class)->hasCachedPage(Request::create('http://localhost/blog/post-b')), 'The static cache must be warm before the write.');
        $this->assertNotNull(app(ResponseCache::class)->get(Request::create('/graphql', 'POST', ['query' => self::GRAPHQL_QUERY])), 'The GraphQL cache must be warm before the write.');

        $result = $write($this);
        $this->assertTrue($result['success'] ?? false, json_encode($result) ?: '');

        $this->assertPages($expected, 'warm');

        Stache::clear();
        app(Cacher::class)->flush();
        Cache::flush();
        $this->assertPages($expected, 'cold');
    }

    public static function cacheModes(): array
    {
        return [['full', false], ['statamic', false], ['refresh', false], ['full', true], ['refresh', true]];
    }

    #[DataProvider('cacheModes')]
    public function test_static_modes_render_fresh_pages_or_preserve_native_invalidation(string $mode, bool $all): void
    {
        config(['statamic.mcp.cache.static_after_write' => $mode, 'statamic.static_caching.invalidation.rules' => $all ? 'all' : [],
            'statamic.static_caching.warm_queue_connection' => 'database']);
        Queue::fake([StaticWarmJob::class]);
        $cacher = new class(Cache::store(), config('statamic.static_caching.strategies.half')) extends ApplicationCacher
        {
            public int $flushes = 0;

            public function flush()
            {
                $this->flushes++;
                parent::flush();
            }
        };
        app(StaticCacheManager::class)->extend('counted', fn () => $cacher);
        config(['statamic.static_caching.strategies.counted' => ['driver' => 'counted'], 'statamic.static_caching.strategy' => 'counted']);
        app()->instance(Cacher::class, $cacher);
        app()->instance(Invalidator::class, new DefaultInvalidator($cacher, $all ? 'all' : []));
        $this->fetch('/home')->assertSee('Post A^');
        $this->fetch('/blog/post-a')->assertSee('POST=Post A');
        $result = (new EntriesRouter)->execute(['action' => 'update', 'collection' => 'blog', 'id' => $this->ids['post-a'], 'data' => ['title' => 'Changed']]);
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame($mode === 'statamic' ? 0 : 1, $cacher->flushes, 'Native rules:all must not be followed by a duplicate full flush.');
        if ($mode === 'refresh') {
            Queue::assertPushed(StaticWarmJob::class, 2);
            $this->assertFalse($cacher->hasCachedPage(Request::create('http://localhost/home')));
            foreach (Queue::pushed(StaticWarmJob::class) as $job) {
                $this->assertFalse($job->clientConfig['allow_redirects']);
                // Exercise the native worker with HTTP responses from the real
                // test kernel instead of opening an external socket.
                $job->clientConfig['handler'] = function ($request) {
                    $response = $this->get((string) $request->getUri());

                    return Create::promiseFor(new Response($response->status(), $response->headers->all(), $response->getContent()));
                };
                $job->handle();
            }
            $this->assertTrue($cacher->hasCachedPage(Request::create('http://localhost/home')));
        } else {
            Queue::assertNothingPushed();
        }
        $this->fetch('/blog/post-a')->assertSee('POST=Changed');
        $this->fetch('/home')->assertSee($mode === 'statamic' ? 'Post A^' : 'Changed^');
    }

    public function test_explicit_invalidation_is_atomic_site_bounded_and_warming_uses_native_jobs(): void
    {
        $this->fetch('/home');
        $this->fetch('/blog/post-a');
        $cacher = app(Cacher::class);
        foreach (['https://other.test/home', 'http://localhost/../secret', 'http://localhost/%2e%2e/secret', 'http://localhost@other.test/home'] as $invalid) {
            $result = (new SystemRouter)->execute(['action' => 'invalidate_urls', 'urls' => ['http://localhost/home', $invalid]]);
            $this->assertFalse($result['success']);
            $this->assertTrue($cacher->hasCachedPage(Request::create('http://localhost/home')));
        }
        $args = ['action' => 'invalidate_urls', 'urls' => ['http://localhost/home'], 'idempotency_key' => (string) Str::uuid()];
        $result = (new SystemRouter)->execute($args);
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertFalse($cacher->hasCachedPage(Request::create('http://localhost/home')));
        $this->assertTrue($cacher->hasCachedPage(Request::create('http://localhost/blog/post-a')));
        $this->fetch('/home');
        $replay = (new SystemRouter)->execute($args);
        $this->assertTrue($replay['data']['idempotent_replay']);
        $this->assertTrue($cacher->hasCachedPage(Request::create('http://localhost/home')));

        $result = (new SystemRouter)->execute(['action' => 'cache_warm', 'cache_type' => 'static']);
        $this->assertFalse($result['success'], 'Sync warming must not block the MCP process.');
        config(['statamic.static_caching.warm_queue_connection' => 'database']);
        Queue::fake();
        $result = (new SystemRouter)->execute(['action' => 'cache_warm', 'cache_type' => 'static']);
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame('queued', $result['data']['cache_warmed']['static']['status']);
        Queue::assertPushed(StaticWarmJob::class, fn ($job) => (string) $job->request->getUri() === 'http://localhost/blog/post-a');
        Queue::assertNotPushed(StaticWarmJob::class, fn ($job) => str_contains((string) $job->request->getUri(), '/draft'));
    }

    public function test_relative_site_urls_do_not_trust_the_request_host(): void
    {
        $this->app->instance('request', Request::create('https://attacker.test/'));
        config(['app.url' => 'https://site.test']);
        $result = (new SystemRouter)->execute(['action' => 'invalidate_urls', 'urls' => ['https://attacker.test/home']]);
        $this->assertFalse($result['success']);
        $result = (new SystemRouter)->execute(['action' => 'invalidate_urls', 'urls' => ['https://site.test/home']]);
        $this->assertTrue($result['success'], json_encode($result));
        config(['app.url' => 'https://site.test/cms']);
        Site::setSites(['default' => ['name' => 'Default', 'url' => '/en', 'locale' => 'en_US']]);
        $result = (new SystemRouter)->execute(['action' => 'invalidate_urls', 'urls' => ['https://site.test/en/home']]);
        $this->assertFalse($result['success']);
        $result = (new SystemRouter)->execute(['action' => 'invalidate_urls', 'urls' => ['https://site.test/cms/en/home']]);
        $this->assertTrue($result['success'], json_encode($result));
    }

    public function test_full_clear_removes_html_even_when_url_metadata_was_lost(): void
    {
        $path = config('statamic.forms.forms') . '/static';
        config(['statamic.static_caching.strategy' => 'full', 'statamic.static_caching.strategies.full.path' => $path,
            'statamic.static_caching.invalidation.rules' => 'all', 'statamic.mcp.cache.static_after_write' => 'full']);
        $manager = app(StaticCacheManager::class);
        $cacher = $manager->driver('full');
        app()->instance(Cacher::class, $cacher);
        app()->instance(Invalidator::class, new DefaultInvalidator($cacher, 'all'));
        $request = Request::create('http://localhost/home');
        $cacher->cachePage($request, 'old rendered page');
        Cache::flush();
        $this->assertTrue($cacher->hasCachedPage($request));
        $this->assertSame([], app(StaticCacheMaintenance::class)->cachedUrls());
        // TaxonomySaved has no native static-cache listener.
        $result = (new StructuresRouter)->execute(['action' => 'update', 'resource_type' => 'taxonomy',
            'handle' => 'tags', 'data' => ['title' => 'Renamed taxonomy']]);
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertFalse($cacher->hasCachedPage($request));
    }

    public static function paginationNames(): array
    {
        return [['page'], ['news,page']];
    }

    #[DataProvider('paginationNames')]
    public function test_warming_pagination_keeps_the_selected_connection_and_queue(string $pageName): void
    {
        config(['statamic.static_caching.warm_queue_connection' => 'database', 'statamic.static_caching.warm_queue' => 'pages']);
        Queue::fake([StaticWarmJob::class]);
        $result = (new SystemRouter)->execute(['action' => 'cache_warm', 'cache_type' => 'static']);
        $this->assertTrue($result['success'], json_encode($result));
        $job = Queue::pushed(StaticWarmJob::class)->first();
        $job->clientConfig['handler'] = fn () => Create::promiseFor(new Response(200, ['X-Statamic-Pagination' => "1,3,{$pageName}"]));
        $job->handle();
        foreach ([1, 2, 3] as $page) {
            Queue::assertPushed(StaticWarmJob::class, fn ($child) => $child->request->getUri()->getQuery() === rawurlencode($pageName) . "={$page}"
                && $child->connection === 'database' && $child->queue === 'pages');
        }
        $child = Queue::pushed(StaticWarmJob::class)->last();
        $count = Queue::pushed(StaticWarmJob::class)->count();
        $child->handle();
        $this->assertCount($count, Queue::pushed(StaticWarmJob::class), 'A pagination page must not recursively enqueue itself.');
    }

    /** @param  array<string, list<string>|int>  $expected */
    private function assertPages(array $expected, string $cache): void
    {
        foreach ($expected as $url => $want) {
            $response = $this->fetch($url);
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

    /** A page, or with "graphql" the blog listing through the GraphQL API. */
    private function fetch(string $url): TestResponse
    {
        return $url === 'graphql'
            ? $this->postJson('/graphql', ['query' => self::GRAPHQL_QUERY])
            : $this->get($url);
    }
}
