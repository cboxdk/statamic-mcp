<?php

namespace Cboxdk\StatamicMcp\Tests;

use Cboxdk\StatamicMcp\ServiceProvider;
use Illuminate\Support\Facades\File;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

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
