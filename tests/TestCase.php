<?php

namespace Cboxdk\StatamicMcp\Tests;

use Cboxdk\StatamicMcp\ServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Server\McpServiceProvider;
use Statamic\Statamic;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    /** @var list<string> */
    private array $permissionFixtureDirectories = [];

    /**
     * @param  Application  $app
     *
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        // Statamic's Stache fixture trait does not isolate roles/groups. Their
        // default resource files survive tests and can grant stale permissions.
        $directory = sys_get_temp_dir() . '/mcp-test-permissions-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $this->permissionFixtureDirectories[] = $directory;
        $app['config']->set('statamic.users.repositories.file.paths.roles', $directory . '/roles.yaml');
        $app['config']->set('statamic.users.repositories.file.paths.groups', $directory . '/groups.yaml');
        // Forms 2 definitions also bypass the Stache fixture trait.
        $app['config']->set('statamic.forms.forms', $directory . '/forms');
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            $files = new Filesystem;
            foreach ($this->permissionFixtureDirectories as $directory) {
                $files->deleteDirectory($directory);
            }
            $this->permissionFixtureDirectories = [];
        }
    }

    /**
     * Register laravel/mcp's own provider alongside the addon's.
     *
     * Testbench does not run package auto-discovery, so without this
     * McpServiceProvider::registerContainerCallbacks() never binds the resolving
     * hook that hands tool arguments to Laravel\Mcp\Request. Tests driving the
     * real protocol surface would then see every argument arrive empty — and
     * pass, misleadingly, because a tool given no arguments still returns a
     * well-formed error envelope.
     *
     * @param  Application  $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app)
    {
        return [
            ...parent::getPackageProviders($app),
            McpServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        // Statamic keeps pushed CP routes and install callbacks in static lists
        // that outlive the application. Every test boots a new app, so each one
        // re-pushed the addon's routes on top of all earlier tests' and then
        // registered every one of them: per-test time grew with the test count
        // until CI jobs hit their timeout. One boot per process in production
        // never sees this.
        foreach (['cpRoutes', 'afterInstalledCallbacks'] as $list) {
            (new \ReflectionProperty(Statamic::class, $list))->setValue(null, []);
        }

        parent::setUp();

        self::sweepLeakedBlueprints();

        // Grants and OAuth fixtures must not survive into another test or run.
        foreach (['statamic-mcp/oauth', 'statamic-mcp/tokens'] as $path) {
            $directory = storage_path($path);
            if (is_dir($directory)) {
                File::deleteDirectory($directory);
            }
        }
    }

    /**
     * Remove blueprints left behind by earlier runs.
     *
     * PreventsSavingStacheItemsToDisk stops Stache writes, but a saved
     * blueprint is a real file under Testbench's skeleton app — inside vendor,
     * so it survives every run. They had accumulated to 3,431 directories and
     * 4,122 files, which every `Blueprint::in()` listing then had to scan.
     *
     * Worse than slow, it is misleading: a test asserting that a refused write
     * left no blueprint behind failed because an identically named file from a
     * previous run was still sitting there, which looks exactly like the fix
     * not working.
     *
     * Swept before every test, not once per process: a blueprint one test
     * saved into a shared collection (collections/blog/legit_handle.yaml)
     * became that collection's default blueprint in a later test, and the
     * GraphQL row of RenderedCacheMatrixTest then failed only in a full run.
     * With nothing left to accumulate, a sweep touches a handful of files.
     */
    private static function sweepLeakedBlueprints(): void
    {
        $base = __DIR__ . '/../vendor/orchestra/testbench-core/laravel/resources/blueprints';

        if (! is_dir($base)) {
            return;
        }

        foreach (['collections', 'taxonomies', 'globals', 'navigation', 'forms', 'assets', 'users'] as $namespace) {
            $path = $base . '/' . $namespace;

            if (is_dir($path)) {
                File::deleteDirectory($path);
            }
        }
    }
}
