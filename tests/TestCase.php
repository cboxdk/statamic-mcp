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

    protected function setUp(): void
    {
        parent::setUp();

        // Clean OAuth file storage between tests to prevent cross-test pollution
        $oauthBasePath = storage_path('statamic-mcp/oauth');
        if (is_dir($oauthBasePath)) {
            File::deleteDirectory($oauthBasePath);
        }
    }
}
