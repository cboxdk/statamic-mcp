<?php

namespace Cboxdk\StatamicMcp\Tests;

use Cboxdk\StatamicMcp\ServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Server\McpServiceProvider;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

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

    /**
     * Whether this process has already swept Testbench's blueprint directory.
     */
    private static bool $blueprintsSwept = false;

    protected function setUp(): void
    {
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
     * Swept once per process rather than per test: the point is to start from
     * a clean slate, and doing it 1,200 times would cost more than it saves.
     */
    private static function sweepLeakedBlueprints(): void
    {
        if (self::$blueprintsSwept) {
            return;
        }

        self::$blueprintsSwept = true;

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
