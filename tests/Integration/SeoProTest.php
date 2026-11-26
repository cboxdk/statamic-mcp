<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Integration;

use Cboxdk\StatamicMcp\Actions\CapabilityRegistry;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Statamic\Addons\Manifest;
use Statamic\Eloquent\Collections\CollectionModel;
use Statamic\Eloquent\Taxonomies\TaxonomyModel;
use Statamic\Events\AddonSettingsSaving;
use Statamic\Facades\Addon;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\User;
use Statamic\SeoPro\Facades\Error;
use Statamic\SeoPro\Facades\Redirect;
use Statamic\SeoPro\Redirects\Eloquent\RedirectModel;
use Statamic\SeoPro\Redirects\RecordRedirectHit;
use Statamic\SeoPro\Reporting\Report;
use Statamic\SeoPro\ServiceProvider;
use Statamic\SeoPro\SiteDefaults\SiteDefaults;

class SeoProTest extends TestCase
{
    private string $token;

    protected function getPackageProviders($app)
    {
        return [...parent::getPackageProviders($app), ...(class_exists(ServiceProvider::class) ? [ServiceProvider::class] : [])];
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);
        if (class_exists(ServiceProvider::class)) {
            $app->make(Manifest::class)->manifest['statamic/seo-pro'] = [
                'id' => 'statamic/seo-pro', 'slug' => null, 'version' => '7.16.0',
                'namespace' => 'Statamic\\SeoPro', 'autoload' => 'src', 'provider' => ServiceProvider::class,
            ];
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(ServiceProvider::class)) {
            $this->markTestSkipped('Install statamic/seo-pro to run its integration suite.');
        }
        config(['statamic.editions.pro' => true, 'statamic.system.multisite' => true]);
        Site::setSites(['default' => ['name' => 'Default', 'url' => '/', 'locale' => 'en_US'],
            'danish' => ['name' => 'Danish', 'url' => '/da/', 'locale' => 'da_DK']]);
        foreach (['seo_pro_redirects', 'seo_pro_errors'] as $store) {
            $path = config('statamic.forms.forms') . '/' . $store;
            Stache::store($store)->directory($path);
        }
        File::deleteDirectory(storage_path('statamic/seopro/reports'));
        Addon::get('statamic/seo-pro')->settings()->set([])->save();
        Role::make()->handle('seo-editor')->permissions(['access cp', 'access default site',
            'view seo redirects', 'create seo redirects', 'edit seo redirects', 'delete seo redirects', 'delete seo errors',
            'edit seo site defaults', 'edit seo section defaults'])->save();
        $user = User::make()->email('seo@example.test')->assignRole('seo-editor');
        $user->save();
        $this->grant($user, ['seo-pro:read', 'seo-pro:write', 'seo-pro:delete']);
    }

    protected function tearDown(): void
    {
        if ($this->app && class_exists(ServiceProvider::class)) {
            File::deleteDirectory(storage_path('statamic/seopro/reports'));
            Addon::get('statamic/seo-pro')->settings()->delete();
        }
        parent::tearDown();
    }

    private function grant($user, array $scopes): void
    {
        $this->token = app(TokenService::class)->createToken($user->id(), 'SEO', $scopes,
            capabilities: app(CapabilityRegistry::class)->forScopes($scopes))['token'];
    }

    private function callAction(string $action, string $type, array $arguments = [])
    {
        $name = 'statamic-seo-pro-' . (in_array($action, ['create', 'update', 'delete', 'generate_report']) ? 'admin' : 'read');

        return $this->postJson('/mcp/statamic', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => ['action' => $action, 'resource_type' => $type, ...$arguments],
                '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => []]]],
            ['Authorization' => 'Bearer ' . $this->token, 'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => $name]);
    }

    public static function drivers(): array
    {
        return [['file'], ['database']];
    }

    private function useDriver(string $driver): void
    {
        if ($driver !== 'database') {
            return;
        }
        foreach (['redirect', 'error'] as $type) {
            $migration = require base_path('vendor/statamic/seo-pro/src/Commands/stubs/create_seo_pro_' . $type . 's_table.php');
            $migration->down();
            $migration->up();
            $class = 'Statamic\\SeoPro\\Redirects\\Eloquent\\' . ucfirst($type) . 'Repository';
            $this->app->bind('Statamic\\SeoPro\\Redirects\\' . ucfirst($type) . 'Repository', $class);
            foreach ($class::bindings() as $contract => $implementation) {
                $this->app->bind($contract, $implementation);
            }
        }
        Redirect::clearResolvedInstances();
    }

    #[DataProvider('drivers')]
    public function test_redirect_crud_versions_replay_site_binding_and_native_error_deletion(string $driver): void
    {
        $this->useDriver($driver);
        config(['statamic.seo-pro.redirects.default_response_code' => 302]);
        $error = Error::make()->site('default')->url('/old')->hits(3)->lastHitAt(now());
        $error->save();
        $foreign = Error::make()->site('danish')->url('/other')->hits(2)->lastHitAt(now());
        $foreign->save();
        $this->callAction('list', 'error', ['site' => 'default'])->assertJsonPath('result.structuredContent.data.total', 1);
        $this->callAction('get', 'error', ['site' => 'default', 'id' => (string) $foreign->id()])->assertJsonPath('result.structuredContent.error.code', 'NOT_FOUND');
        $this->callAction('list', 'error', ['site' => 'danish'])->assertJsonPath('result.structuredContent.error.code', 'FORBIDDEN');
        $args = ['site' => 'default', 'data' => ['source' => '/old', 'destination' => '/new', 'description' => 'Keep'], 'idempotency_key' => 'create-redirect'];
        $created = $this->callAction('create', 'redirect', $args)->assertJsonPath('result.structuredContent.success', true)
            ->assertJsonPath('result.structuredContent.data.record.response_code', 302);
        $record = $created->json('result.structuredContent.data.record');
        $this->assertNull(Error::find($error->id()));
        $this->assertNotNull(Error::find($foreign->id()));
        $this->callAction('create', 'redirect', $args)->assertJsonPath('result.structuredContent.data.idempotent_replay', true);
        $this->assertSame(1, Redirect::query()->count());
        config(['statamic.seo-pro.redirects.default_response_code' => 301]);
        $args = ['site' => 'default', 'id' => $record['id'], 'expected_version' => $record['version'], 'data' => ['destination' => '/next']];
        $updated = $this->callAction('update', 'redirect', $args)->assertJsonPath('result.structuredContent.data.record.destination', '/next')
            ->assertJsonPath('result.structuredContent.data.record.response_code', 302)
            ->assertJsonPath('result.structuredContent.data.record.description', 'Keep');
        $this->callAction('update', 'redirect', $args)->assertJsonPath('result.structuredContent.error.code', 'VERSION_CONFLICT');
        if ($driver === 'database') {
            $this->assertSame('/next', DB::table('seo_pro_redirects')->where('id', $record['id'])->value('destination'));
            $this->assertSame(1, DB::table('seo_pro_errors')->count());
        }
        $delete = ['site' => 'default', 'id' => $record['id'], 'expected_version' => $updated->json('result.structuredContent.data.record.version'), 'idempotency_key' => 'delete-once'];
        $this->callAction('delete', 'redirect', $delete)->assertJsonPath('result.structuredContent.data.record.deleted', true);
        $this->callAction('delete', 'redirect', $delete)->assertJsonPath('result.structuredContent.data.idempotent_replay', true);
        $this->assertNull(Redirect::find($record['id']));
    }

    public function test_redirect_creation_requires_delete_authority_and_approval(): void
    {
        $user = User::findByEmail('seo@example.test');
        $this->grant($user, ['seo-pro:read', 'seo-pro:write']);
        $args = ['site' => 'default', 'data' => ['source' => '/old', 'destination' => '/new']];
        $this->callAction('create', 'redirect', $args)->assertStatus(403);
        $this->assertSame(0, Redirect::query()->count());
        $this->grant($user, ['seo-pro:read', 'seo-pro:write', 'seo-pro:delete']);
        Role::find('seo-editor')->removePermission('delete seo errors')->save();
        $this->callAction('create', 'redirect', $args)->assertJsonPath('result.structuredContent.error.code', 'FORBIDDEN');
        Role::find('seo-editor')->addPermission('delete seo errors')->save();
        $error = Error::make()->site('default')->url('/old');
        $error->save();
        config(['statamic.mcp.tools.seo-pro.resources.write' => ['redirect:default']]);
        $this->callAction('create', 'redirect', $args)->assertJsonPath('result.structuredContent.error.code', 'FORBIDDEN');
        $this->assertNotNull(Error::find($error->id()));
        config(['statamic.mcp.tools.seo-pro.resources.write' => null]);
        config(['statamic.mcp.confirmation.enabled' => true]);
        $this->callAction('create', 'redirect', $args)->assertJsonPath('result.structuredContent.error.code', 'APPROVAL_REQUIRED');
        $this->assertSame(0, Redirect::query()->count());
    }

    public function test_redirect_receipts_stay_in_the_named_site_even_with_colliding_file_ids(): void
    {
        foreach (['danish' => '/secret', 'default' => '/public'] as $site => $destination) {
            Redirect::make()->site($site)->source('/same')->destination($destination)->responseCode(301)->enabled(true)->save();
        }
        $this->callAction('update', 'redirect', ['site' => 'default', 'id' => 'same', 'data' => ['destination' => '/changed']])
            ->assertJsonPath('result.structuredContent.data.record.site', 'default')
            ->assertJsonPath('result.structuredContent.data.record.destination', '/changed');
        $this->assertSame('/secret', Redirect::query()->where('site', 'danish')->first()->destination());
    }

    public function test_redirect_updates_preserve_native_hit_metadata(): void
    {
        $redirect = Redirect::make()->site('default')->source('/hit')->destination('/before')->responseCode(301)->enabled(true);
        $redirect->save();
        (new RecordRedirectHit($redirect->id()))->handle();
        $hit = Redirect::find($redirect->id())->lastHitAt();
        $this->callAction('update', 'redirect', ['site' => 'default', 'id' => $redirect->id(), 'data' => ['destination' => '/after']])
            ->assertJsonPath('result.structuredContent.data.record.destination', '/after')
            ->assertJsonPath('result.structuredContent.data.record.hits', 1)
            ->assertJsonPath('result.structuredContent.data.record.last_hit_at', $hit);
    }

    public function test_redirect_approval_is_site_bound_stale_checked_and_consumed(): void
    {
        Role::find('seo-editor')->addPermission('view mcp dashboard')->save();
        $this->actingAs(User::findByEmail('seo@example.test'));
        config(['statamic.mcp.confirmation.enabled' => true, 'statamic.users.elevated_sessions_enabled' => true]);
        $redirect = Redirect::make()->site('default')->source('/approval')->destination('/before')->responseCode(301)->enabled(true);
        $redirect->save();
        $args = ['site' => 'default', 'id' => $redirect->id()];
        $approval = $this->callAction('delete', 'redirect', $args)->json('result.structuredContent.data.approval_id');
        $this->withSession(['statamic_elevated_session' => now()->timestamp])
            ->postJson(cp_route('statamic-mcp.approvals.approve', ['approval' => $approval]))->assertOk();
        $redirect->destination('/changed')->save();
        $this->callAction('delete', 'redirect', [...$args, 'approval_id' => $approval])->assertJsonPath('result.structuredContent.error.code', 'APPROVAL_STALE');
        $approval = $this->callAction('delete', 'redirect', $args)->json('result.structuredContent.data.approval_id');
        $this->postJson(cp_route('statamic-mcp.approvals.approve', ['approval' => $approval]))->assertOk();
        $this->callAction('delete', 'redirect', [...$args, 'approval_id' => $approval])->assertJsonPath('result.structuredContent.data.record.deleted', true);
        $this->callAction('delete', 'redirect', [...$args, 'approval_id' => $approval])->assertJsonPath('result.structuredContent.data.approval_replay', true);
        $this->assertNull(Redirect::find($redirect->id()));
    }

    public function test_defaults_veto_and_malformed_opening_hours_never_claim_success(): void
    {
        $hours = ['monday' => ['opening' => '08:30', 'closing' => '17:00']];
        SiteDefaults::in('default')->set('site_name', 'Original')->set('json_ld_entity', 'local_business')
            ->set('json_ld_entity_opening_hours', $hours)->save();
        $this->callAction('update', 'site', ['site' => 'default', 'data' => ['json_ld_entity_opening_hours' => 'not a schedule']])
            ->assertJsonPath('result.structuredContent.success', false);
        $this->assertSame($hours, SiteDefaults::in('default')->all()['json_ld_entity_opening_hours']);
        foreach ([['monday' => 'invalid'], ['monday' => ['opening' => '09:00']], ['monday' => ['opening' => '99:00', 'closing' => '17:00']]] as $invalid) {
            $this->callAction('update', 'site', ['site' => 'default', 'data' => ['json_ld_entity_opening_hours' => $invalid]])
                ->assertJsonPath('result.structuredContent.success', false);
        }
        $hours['monday']['closing'] = '18:15';
        $this->callAction('update', 'site', ['site' => 'default', 'data' => ['json_ld_entity_opening_hours' => $hours]])
            ->assertJsonPath('result.structuredContent.data.record.data.json_ld_entity_opening_hours', $hours);
        Event::listen(AddonSettingsSaving::class, fn () => false);
        $this->callAction('update', 'site', ['site' => 'default', 'data' => ['site_name' => 'Not saved']])
            ->assertJsonPath('result.structuredContent.success', false);
        $this->assertSame('Original', Addon::get('statamic/seo-pro')->settings()->raw()['site_defaults']['default']['site_name']);
    }

    public function test_inherited_defaults_validate_with_origin_but_save_only_local_overrides(): void
    {
        SiteDefaults::in('default')->set('site_name_separator', '~')->save();
        SiteDefaults::origins(['default' => null, 'danish' => 'default']);
        Role::find('seo-editor')->addPermission('access danish site')->save();
        $this->callAction('update', 'site', ['site' => 'danish', 'data' => ['site_name' => 'Dansk']])
            ->assertJsonPath('result.structuredContent.data.record.data.site_name', 'Dansk');
        $this->assertSame(['site_name' => 'Dansk'], SiteDefaults::in('danish')->all());
        $this->assertSame('~', SiteDefaults::in('danish')->values()->get('site_name_separator'));
    }

    public function test_eloquent_vetoes_do_not_produce_successful_write_receipts(): void
    {
        $this->useDriver('database');
        $redirect = Redirect::make()->site('default')->source('/veto')->destination('/before')->responseCode(301)->enabled(true);
        $redirect->save();
        RedirectModel::saving(fn () => false);
        RedirectModel::deleting(fn () => false);
        $this->callAction('update', 'redirect', ['site' => 'default', 'id' => (string) $redirect->id(), 'data' => ['destination' => '/after']])
            ->assertJsonPath('result.structuredContent.success', false);
        $this->callAction('delete', 'redirect', ['site' => 'default', 'id' => (string) $redirect->id()])
            ->assertJsonPath('result.structuredContent.success', false);
        $this->assertSame('/before', DB::table('seo_pro_redirects')->where('id', $redirect->id())->value('destination'));
    }

    #[DataProvider('drivers')]
    public function test_defaults_preserve_omitted_fields_child_seo_and_other_addon_settings(string $driver): void
    {
        if ($driver === 'database') {
            if (! class_exists(\Statamic\Eloquent\ServiceProvider::class)) {
                $this->markTestSkipped('Install statamic/eloquent-driver for defaults repository coverage.');
            }
            $path = dirname((new \ReflectionClass(\Statamic\Eloquent\ServiceProvider::class))->getFileName(), 2);
            config(['statamic.eloquent-driver' => require $path . '/config/eloquent-driver.php']);
            foreach (['collections', 'taxonomies', 'addon_settings'] as $type) {
                config(["statamic.eloquent-driver.{$type}.driver" => 'eloquent']);
                $date = $type === 'addon_settings' ? '2025_07_07' : '2024_03_07';
                $migration = require $path . "/database/migrations/{$date}_100000_create_{$type}_table.php";
                $migration->down();
                $migration->up();
            }
            (new \Statamic\Eloquent\ServiceProvider($this->app))->register();
            Collection::clearResolvedInstances();
            Blink::swap(new \Statamic\Support\Blink);
        }
        Addon::get('statamic/seo-pro')->settings()->set('private_key', 'never-return')->save();
        SiteDefaults::in('default')->set('site_name', 'Original')->set('site_name_separator', '~')->save();
        $read = $this->callAction('get', 'site', ['site' => 'default'])->assertJsonPath('result.structuredContent.success', true);
        $this->assertStringNotContainsString('never-return', $read->getContent());
        $this->callAction('update', 'site', ['site' => 'default', 'expected_version' => $read->json('result.structuredContent.data.record.version'),
            'data' => ['site_name' => 'Changed']])->assertJsonPath('result.structuredContent.data.record.data.site_name', 'Changed')
            ->assertJsonPath('result.structuredContent.data.record.data.site_name_separator', '~');
        $this->assertSame('never-return', Addon::get('statamic/seo-pro')->settings()->get('private_key'));
        if ($driver === 'database') {
            $settings = json_decode(DB::table('addon_settings')->where('addon', 'statamic/seo-pro')->value('settings'), true);
            $this->assertSame('Changed', $settings['site_defaults']['default']['site_name']);
        }
        foreach (['collection', 'taxonomy'] as $type) {
            $section = ($type === 'collection' ? Collection::make('posts') : Taxonomy::make('tags'))->cascade(['seo' => ['title' => 'Original', 'description' => 'Keep']]);
            $section->save();
            $child = $type === 'collection' ? Entry::make()->collection('posts') : Term::make()->taxonomy('tags');
            $child->slug('child')->data(['title' => 'Child', 'seo' => ['title' => 'Child SEO']])->save();
            $args = ['handle' => $section->handle()];
            $this->callAction('update', $type, [...$args, 'data' => ['title' => 'Changed']])
                ->assertJsonPath('result.structuredContent.data.record.data.title', 'Changed')
                ->assertJsonPath('result.structuredContent.data.record.data.description', 'Keep');
            if ($driver === 'database') {
                $table = $type === 'collection' ? 'collections' : 'taxonomies';
                $settings = json_decode(DB::table($table)->where('handle', $section->handle())->value('settings'), true);
                $this->assertSame('Changed', $settings['inject']['seo']['title']);
                $model = $type === 'collection' ? CollectionModel::class : TaxonomyModel::class;
                $model::saving(fn () => false);
                $this->callAction('update', $type, [...$args, 'data' => ['title' => 'Vetoed']])->assertJsonPath('result.structuredContent.success', false);
                $model::flushEventListeners();
            }
            $this->callAction('update', $type, [...$args, 'data' => ['enabled' => false]])->assertJsonPath('result.structuredContent.data.record.data', false);
            $fresh = $type === 'collection' ? Entry::find($child->id()) : Term::find($child->id());
            $this->assertSame(['title' => 'Child SEO'], $fresh->get('seo'));
            config(['statamic.mcp.tools.structures.resources.write' => []]);
            $this->callAction('update', $type, [...$args, 'data' => ['enabled' => true]])->assertJsonPath('result.structuredContent.error.code', 'FORBIDDEN');
            config(['statamic.mcp.tools.structures.resources.write' => null]);
        }
        config(['statamic.mcp.tools.seo-pro.denied_fields' => ['site_name']]);
        $this->callAction('update', 'site', ['site' => 'default', 'data' => ['site_name' => 'Forbidden', 'site_name_separator' => '/']])->assertJsonPath('result.structuredContent.success', true);
        $this->assertSame('Changed', SiteDefaults::in('default')->all()['site_name']);
        $this->assertStringNotContainsString('Changed', $this->callAction('get', 'site', ['site' => 'default'])->getContent());
    }

    public function test_global_reports_require_an_explicit_unrestricted_super_grant_and_reads_never_generate(): void
    {
        Queue::fake();
        Collection::make('report_pages')->routes('/{slug}')->save();
        Entry::make()->collection('report_pages')->slug('report-page')->data(['title' => 'Report page'])->save();
        $super = User::make()->email('super-seo@example.test')->makeSuper();
        $super->save();
        $this->grant($super, ['seo-pro:site-wide']);
        $this->callAction('generate_report', 'report')->assertStatus(403);
        $this->grant($super, ['seo-pro:site-wide', 'seo-pro:write']);
        $created = $this->callAction('generate_report', 'report', ['idempotency_key' => 'report-once'])->assertJsonPath('result.structuredContent.success', true);
        $id = $created->json('result.structuredContent.data.record.id');
        Queue::assertPushed(QueuedCommand::class, 1);
        $this->callAction('generate_report', 'report', ['idempotency_key' => 'report-once'])->assertJsonPath('result.structuredContent.data.idempotent_replay', true);
        Queue::assertPushed(QueuedCommand::class, 1);
        $before = File::get((new Report)->setId((int) $id)->path());
        $this->callAction('list_reports', 'report')->assertJsonPath('result.structuredContent.data.total', 1);
        $this->callAction('get_report', 'report', ['id' => $id])->assertJsonPath('result.structuredContent.success', true);
        $this->assertSame($before, File::get((new Report)->setId((int) $id)->path()));
        Queue::assertPushed(QueuedCommand::class, 1);
        $this->callAction('sitemap', 'sitemap')->assertJsonPath('result.structuredContent.success', true);
        config(['statamic.mcp.tools.entries.resources.read' => ['public']]);
        foreach (['get_report' => ['id' => $id], 'generate_report' => [], 'list_reports' => []] as $action => $args) {
            $this->callAction($action, 'report', $args)->assertJsonPath('result.structuredContent.error.code', 'FORBIDDEN');
        }
        $this->callAction('sitemap', 'sitemap')->assertJsonPath('result.structuredContent.error.code', 'FORBIDDEN');
        config(['statamic.mcp.tools.entries.resources.read' => null, 'statamic.mcp.tools.terms.denied_fields' => ['title']]);
        $this->callAction('get_report', 'report', ['id' => $id])->assertJsonPath('result.structuredContent.error.code', 'FORBIDDEN');
    }

    public function test_native_report_generation_completes_and_retention_applies_without_read_side_writes(): void
    {
        $super = User::make()->email('report-worker@example.test')->makeSuper();
        $super->save();
        $this->grant($super, ['seo-pro:site-wide', 'seo-pro:write']);
        $this->callAction('generate_report', 'report')->assertJsonPath('result.structuredContent.error.code', 'VALIDATION');
        Collection::make('report_pages')->routes('/{slug}')->save();
        Entry::make()->collection('report_pages')->slug('report-page')->data(['title' => 'Report page'])->save();
        config(['statamic.seo-pro.reports.keep_recent' => 1]);
        $created = $this->callAction('generate_report', 'report')->assertJsonPath('result.structuredContent.success', true);
        $id = $created->json('result.structuredContent.data.record.id');
        $this->assertNotContains($created->json('result.structuredContent.data.record.status'), ['pending', 'generating']);
        $this->assertSame(1, $created->json('result.structuredContent.data.record.pages_crawled'));
        $path = (new Report)->setId((int) $id)->path();
        $before = File::get($path);
        $this->callAction('get_report', 'report', ['id' => $id])->assertJsonPath('result.structuredContent.success', true);
        $this->assertSame($before, File::get($path));
        $next = $this->callAction('generate_report', 'report')->assertJsonPath('result.structuredContent.success', true);
        $this->assertNotSame($id, $next->json('result.structuredContent.data.record.id'));
        $this->assertFileDoesNotExist($path);
    }
}
