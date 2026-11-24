<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Integration;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\AssetsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\GlobalsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\SystemRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\TermsRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Statamic\Contracts\Structures\CollectionTreeRepository;
use Statamic\Contracts\Structures\Tree;
use Statamic\Eloquent\Entries\EntryRepository;
use Statamic\Eloquent\Entries\UuidEntryModel;
use Statamic\Eloquent\ServiceProvider;
use Statamic\Eloquent\Structures\CollectionTreeRepository as EloquentCollectionTreeRepository;
use Statamic\Facades\Blink;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Form;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Stache\Stache;

/**
 * The built-in write contract on statamic/eloquent-driver, the supported
 * database repository: what each write stores is read back from the tables,
 * not from Statamic's in-memory objects. CI installs the driver in its own
 * job; without it these tests skip.
 */
class EloquentDriverTest extends TestCase
{
    /** Kept on files: MCP neither reads nor writes these. */
    private const FILE_DRIVERS = ['sites', 'tokens', 'addon_settings'];

    protected function getPackageProviders($app)
    {
        return [...parent::getPackageProviders($app), ...(class_exists(ServiceProvider::class) ? [ServiceProvider::class] : [])];
    }

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);
        if (! class_exists(ServiceProvider::class)) {
            return;
        }
        $app['config']->set('statamic.eloquent-driver', require self::driverPath('config/eloquent-driver.php'));
        foreach ($app['config']->get('statamic.eloquent-driver') as $key => $config) {
            if (is_array($config) && isset($config['driver']) && ! in_array($key, self::FILE_DRIVERS, true)) {
                $app['config']->set("statamic.eloquent-driver.{$key}.driver", 'eloquent');
            }
        }
        $app['config']->set('statamic.eloquent-driver.entries.model', UuidEntryModel::class);
    }

    protected function defineDatabaseMigrations()
    {
        if (class_exists(ServiceProvider::class)) {
            $this->loadMigrationsFrom(self::driverPath('database/migrations'));
            $this->loadMigrationsFrom(self::driverPath('database/migrations/entries/2024_03_07_100000_create_entries_table_with_string_ids.php'));
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(ServiceProvider::class)) {
            $this->markTestSkipped('Install statamic/eloquent-driver to run the Eloquent integration suite.');
        }
        config(['statamic.revisions.enabled' => false]);
        Blueprint::make('page')->setNamespace('collections.pages')->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]])->save();
        Collection::make('pages')->routes('/{slug}')->structureContents(['root' => false, 'max_depth' => 3])->save();
    }

    private static function driverPath(string $path): string
    {
        return dirname((string) (new \ReflectionClass(ServiceProvider::class))->getFileName(), 2) . '/' . $path;
    }

    /** @return array<string, mixed> */
    private function entries(array $arguments): array
    {
        return (new EntriesRouter)->execute(['collection' => 'pages', ...$arguments]);
    }

    private function createPage(string $slug): string
    {
        $result = $this->entries(['action' => 'create', 'slug' => $slug, 'data' => ['title' => strtoupper($slug)]]);
        $this->assertTrue($result['success'], json_encode($result) ?: '');

        return $result['data']['entry']['id'];
    }

    public function test_entry_writes_land_in_the_entries_table(): void
    {
        $this->assertInstanceOf(EntryRepository::class, app(\Statamic\Contracts\Entries\EntryRepository::class));
        $id = $this->createPage('about');
        $this->assertSame('ABOUT', UuidEntryModel::find($id)?->data['title']);

        $update = $this->entries(['action' => 'update', 'id' => $id, 'data' => ['title' => 'About us', 'slug' => 'about-us']]);
        $this->assertTrue($update['success'], json_encode($update) ?: '');
        $this->assertSame(['About us', 'about-us'], [UuidEntryModel::find($id)?->data['title'], UuidEntryModel::find($id)?->slug]);

        $this->assertTrue($this->entries(['action' => 'unpublish', 'id' => $id])['success']);
        $this->assertFalse((bool) UuidEntryModel::find($id)?->published);

        $this->assertTrue($this->entries(['action' => 'delete', 'id' => $id])['success']);
        $this->assertNull(UuidEntryModel::find($id));
    }

    public function test_the_version_a_write_returns_is_the_version_the_next_write_accepts(): void
    {
        $created = $this->entries(['action' => 'create', 'slug' => 'versioned', 'data' => ['title' => 'One']]);
        $id = $created['data']['entry']['id'];
        $read = $this->entries(['action' => 'get', 'id' => $id]);
        $this->assertSame($created['data']['entry']['version'], $read['data']['entry']['version'], 'create and get must agree on an unchanged entry');

        $first = $this->entries(['action' => 'update', 'id' => $id, 'expected_version' => $created['data']['entry']['version'], 'data' => ['title' => 'Two']]);
        $this->assertTrue($first['success'], json_encode($first) ?: '');
        $second = $this->entries(['action' => 'update', 'id' => $id, 'expected_version' => $first['data']['entry']['version'], 'data' => ['title' => 'Three']]);
        $this->assertTrue($second['success'], json_encode($second) ?: '');

        $stale = $this->entries(['action' => 'update', 'id' => $id, 'expected_version' => $first['data']['entry']['version'], 'data' => ['title' => 'Lost']]);
        $this->assertSame('VERSION_CONFLICT', $stale['code'] ?? $stale['data']['code'] ?? null, json_encode($stale) ?: '');
        $this->assertSame('Three', UuidEntryModel::find($id)?->data['title']);
    }

    public function test_a_tree_move_is_stored_in_the_tree_table(): void
    {
        $parent = $this->createPage('parent');
        $child = $this->createPage('child');

        $move = $this->entries(['action' => 'update', 'id' => $child, 'data' => ['parent' => $parent]]);
        $this->assertTrue($move['success'], json_encode($move) ?: '');

        $stored = DB::table('trees')->where('handle', 'pages')->where('type', 'collection')->value('tree');
        $this->assertSame([['entry' => $parent, 'children' => [['entry' => $child]]]], json_decode((string) $stored, true));
    }

    public function test_a_tree_changed_by_another_writer_is_not_overwritten(): void
    {
        $parent = $this->createPage('parent');
        $child = $this->createPage('child');
        $this->assertTrue($this->entries(['action' => 'update', 'id' => $child, 'data' => ['parent' => $parent]])['success'], 'a stored tree to contend over');

        // Another writer commits a tree change after this call planned its move
        // and before it saves: on the fresh read the check before saving makes.
        $this->app->instance(CollectionTreeRepository::class, new class(app(Stache::class)) extends EloquentCollectionTreeRepository
        {
            private bool $written = false;

            public function find(string $handle, string $site): ?Tree
            {
                $calls = array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6), 'function');
                if (! $this->written && in_array('storedTreeVersion', $calls, true) && ! in_array('plan', $calls, true)) {
                    $this->written = true;
                    $tree = parent::find($handle, $site);
                    DB::table('trees')->where('handle', $handle)->where('type', 'collection')
                        ->update(['tree' => json_encode([...($tree?->tree() ?? []), ['entry' => 'elsewhere']])]);
                    Blink::flush();
                }

                return parent::find($handle, $site);
            }
        });

        $move = $this->entries(['action' => 'update', 'id' => $child, 'data' => ['parent' => null]]);

        $this->assertFalse($move['success'], json_encode($move) ?: '');
        $this->assertStringContainsString('The tree changed', json_encode($move) ?: '');
        $stored = json_decode((string) DB::table('trees')->where('handle', 'pages')->where('type', 'collection')->value('tree'), true);
        $this->assertContains(['entry' => 'elsewhere'], $stored, 'the other writer\'s tree must survive');
    }

    public function test_terms_globals_navigation_collection_and_blueprint_writes_reach_their_tables(): void
    {
        Taxonomy::make('tags')->save();
        Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP'])->save();
        $term = (new TermsRouter)->execute(['action' => 'get', 'taxonomy' => 'tags', 'slug' => 'php']);
        $update = (new TermsRouter)->execute(['action' => 'update', 'taxonomy' => 'tags', 'slug' => 'php',
            'expected_version' => $term['data']['term']['version'], 'data' => ['title' => 'PHP 8']]);
        $this->assertTrue($update['success'], json_encode($update) ?: '');
        $this->assertSame('PHP 8', json_decode((string) DB::table('taxonomy_terms')->where('slug', 'php')->value('data'), true)['title']);

        $set = GlobalSet::make('settings')->title('Settings');
        $set->save();
        $set->makeLocalization('default')->data(['tagline' => 'Old'])->save();
        Blueprint::make('settings')->setNamespace('globals')->setContents(['fields' => [['handle' => 'tagline', 'field' => ['type' => 'text']]]])->save();
        $this->assertTrue((new GlobalsRouter)->execute(['action' => 'update', 'handle' => 'settings', 'data' => ['tagline' => 'New']])['success']);
        $this->assertSame('New', json_decode((string) DB::table('global_set_variables')->where('handle', 'settings')->value('data'), true)['tagline']);

        $page = $this->createPage('home');
        Nav::make('main')->title('Main')->collections(['pages'])->maxDepth(2)->save();
        $nav = (new StructuresRouter)->execute(['action' => 'update', 'resource_type' => 'navigation', 'handle' => 'main',
            'data' => ['trees' => [['site' => 'default', 'items' => [['entry' => $page]]]]]]);
        $this->assertTrue($nav['success'], json_encode($nav) ?: '');
        $navTree = json_decode((string) DB::table('trees')->where('handle', 'main')->where('type', 'navigation')->value('tree'), true);
        $this->assertSame($page, $navTree[0]['entry'] ?? null, json_encode($navTree));

        $route = (new StructuresRouter)->execute(['action' => 'update', 'resource_type' => 'collection', 'handle' => 'pages', 'data' => ['route' => '/p/{slug}']]);
        $this->assertTrue($route['success'], json_encode($route) ?: '');
        $this->assertSame('/p/{slug}', json_decode((string) DB::table('collections')->where('handle', 'pages')->value('settings'), true)['routes']);
        // findByUri() reads the stored column: a stale one 404s the new URL.
        $this->assertSame('/p/home', UuidEntryModel::find($page)?->uri);
        $this->assertSame($page, Entry::findByUri('/p/home')?->id());

        $blueprint = (new BlueprintsRouter)->execute(['action' => 'update', 'namespace' => 'collections', 'collection_handle' => 'pages', 'handle' => 'page',
            'fields' => [['handle' => 'body', 'field' => ['type' => 'textarea']]]]);
        $this->assertTrue($blueprint['success'], json_encode($blueprint) ?: '');
        $this->assertStringContainsString('textarea', (string) DB::table('blueprints')->where('handle', 'page')->value('data'));
    }

    public function test_a_mount_change_and_a_renamed_mount_page_move_the_stored_uris(): void
    {
        $about = $this->createPage('about');
        $company = $this->createPage('company');
        Collection::make('news')->routes('{mount}/{slug}')->mount($about)->save();
        $launch = Entry::make()->collection('news')->slug('launch')->data(['title' => 'Launch']);
        $launch->save();
        $this->assertSame('/about/launch', UuidEntryModel::find($launch->id())?->uri);

        $mount = (new StructuresRouter)->execute(['action' => 'update', 'resource_type' => 'collection', 'handle' => 'news', 'data' => ['mount' => $company]]);
        $this->assertTrue($mount['success'], json_encode($mount) ?: '');
        // findByUri() reads the stored column: a stale one 404s the new URL.
        $this->assertSame('/company/launch', UuidEntryModel::find($launch->id())?->uri);

        $rename = $this->entries(['action' => 'update', 'id' => $company, 'data' => ['slug' => 'firm']]);
        $this->assertTrue($rename['success'], json_encode($rename) ?: '');
        $this->assertSame('/firm/launch', UuidEntryModel::find($launch->id())?->uri);
        $this->assertSame((string) $launch->id(), Entry::findByUri('/firm/launch')?->id());
    }

    public function test_asset_writes_land_in_the_asset_tables(): void
    {
        config(['filesystems.disks.assets' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/assets')]]);
        Storage::fake('assets');
        $assets = new AssetsRouter;

        $container = $assets->execute(['action' => 'create', 'resource_type' => 'container', 'data' => ['handle' => 'images', 'title' => 'Images', 'disk' => 'assets']]);
        $this->assertTrue($container['success'], json_encode($container) ?: '');
        $this->assertSame('Images', DB::table('asset_containers')->where('handle', 'images')->value('title'));

        $create = $assets->execute(['action' => 'create', 'resource_type' => 'asset', 'container' => 'images', 'filename' => 'hero.txt', 'content' => 'hero']);
        $this->assertTrue($create['success'], json_encode($create) ?: '');
        $this->assertSame(1, DB::table('assets_meta')->where('container', 'images')->where('path', 'hero.txt')->count());

        $update = $assets->execute(['action' => 'update', 'resource_type' => 'asset', 'container' => 'images', 'path' => 'hero.txt', 'data' => ['alt' => 'A hero']]);
        $this->assertTrue($update['success'], json_encode($update) ?: '');
        $meta = json_decode((string) DB::table('assets_meta')->where('path', 'hero.txt')->value('meta'), true);
        $this->assertSame('A hero', $meta['data']['alt'] ?? null, json_encode($meta));

        $move = $assets->execute(['action' => 'move', 'resource_type' => 'asset', 'container' => 'images', 'path' => 'hero.txt', 'destination' => 'archive/hero.txt']);
        $this->assertTrue($move['success'], json_encode($move) ?: '');
        $this->assertSame(['archive/hero.txt'], DB::table('assets_meta')->where('container', 'images')->pluck('path')->all());

        $copy = $assets->execute(['action' => 'copy', 'resource_type' => 'asset', 'container' => 'images', 'path' => 'archive/hero.txt', 'destination' => 'hero-copy.txt']);
        $this->assertTrue($copy['success'], json_encode($copy) ?: '');
        $copied = json_decode((string) DB::table('assets_meta')->where('path', 'hero-copy.txt')->value('meta'), true);
        $this->assertSame('A hero', $copied['data']['alt'] ?? null, json_encode($copied));

        $delete = $assets->execute(['action' => 'delete', 'resource_type' => 'asset', 'container' => 'images', 'path' => 'hero-copy.txt']);
        $this->assertTrue($delete['success'], json_encode($delete) ?: '');
        $this->assertSame(0, DB::table('assets_meta')->where('path', 'hero-copy.txt')->count());
        $this->assertFalse(Storage::disk('assets')->exists('hero-copy.txt'));
    }

    public function test_revision_writes_land_in_the_revisions_table(): void
    {
        config(['statamic.editions.pro' => true, 'statamic.revisions.enabled' => true]);
        Collection::make('posts')->routes('/posts/{slug}')->revisionsEnabled(true)->save();
        Blueprint::make('post')->setNamespace('collections.posts')->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]])->save();
        $entry = Entry::make()->collection('posts')->slug('live')->published(true)->data(['title' => 'Live']);
        $entry->save();
        $id = (string) $entry->id();
        $call = fn (array $arguments) => (new EntriesRouter)->execute(['collection' => 'posts', 'id' => $id, ...$arguments]);

        $edit = $call(['action' => 'update', 'data' => ['title' => 'Draft']]);
        $this->assertTrue($edit['success'], json_encode($edit) ?: '');
        $this->assertSame('Live', UuidEntryModel::find($id)?->data['title']);
        $working = DB::table('revisions')->where('action', 'working')->value('attributes');
        $this->assertSame('Draft', json_decode((string) $working, true)['data']['title'] ?? null);

        $publish = $call(['action' => 'publish_working_copy']);
        $this->assertTrue($publish['success'], json_encode($publish) ?: '');
        $this->assertSame('Draft', UuidEntryModel::find($id)?->data['title']);
        $this->assertSame(0, DB::table('revisions')->where('action', 'working')->count());
        $this->assertSame(1, DB::table('revisions')->where('action', 'publish')->count());

        $list = $call(['action' => 'list_revisions']);
        $this->assertTrue($list['success'], json_encode($list) ?: '');
        $this->assertNotEmpty($list['data']['revisions']);
        $restore = $call(['action' => 'restore_revision', 'revision_id' => $list['data']['revisions'][0]['id']]);
        $this->assertTrue($restore['success'], json_encode($restore) ?: '');
        $this->assertSame(1, DB::table('revisions')->where('action', 'working')->count());
    }

    public function test_forms_on_the_database_are_read_like_files(): void
    {
        Form::make('contact')->title('Contact')->save();
        $this->assertSame(1, DB::table('forms')->where('handle', 'contact')->count());

        $blueprint = (new BlueprintsRouter)->execute(['action' => 'create', 'namespace' => 'forms', 'handle' => 'contact',
            'fields' => [['handle' => 'email', 'field' => ['type' => 'text']]]]);
        $this->assertTrue($blueprint['success'], json_encode($blueprint) ?: '');
        $this->assertStringContainsString('email', (string) DB::table('blueprints')->where('namespace', 'forms')->where('handle', 'contact')->value('data'));

        $info = (new SystemRouter)->execute(['action' => 'info', 'include_details' => true]);
        $this->assertTrue($info['success'], json_encode($info) ?: '');
        $this->assertStringContainsString('"form_count":1', json_encode($info) ?: '');
    }
}
