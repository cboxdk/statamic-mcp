<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\OAuth;

use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\OAuth\Drivers\BuiltInOAuthDriver;
use Cboxdk\StatamicMcp\Tests\Concerns\OAuthDriverContractTests;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Facades\File;

class BuiltInOAuthDriverTest extends TestCase
{
    use OAuthDriverContractTests;

    private string $clientsDir;

    private string $codesDir;

    private string $refreshDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientsDir = sys_get_temp_dir() . '/statamic-mcp-test-oauth-clients-' . uniqid();
        $this->codesDir = sys_get_temp_dir() . '/statamic-mcp-test-oauth-codes-' . uniqid();
        $this->refreshDir = sys_get_temp_dir() . '/statamic-mcp-test-oauth-refresh-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->clientsDir)) {
            File::deleteDirectory($this->clientsDir);
        }

        if (is_dir($this->codesDir)) {
            File::deleteDirectory($this->codesDir);
        }

        if (is_dir($this->refreshDir)) {
            File::deleteDirectory($this->refreshDir);
        }

        parent::tearDown();
    }

    protected function createDriver(): OAuthDriver
    {
        return new BuiltInOAuthDriver($this->clientsDir, $this->codesDir, $this->refreshDir);
    }
}
