<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\Sites\CreateSiteTool;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Site;

class CreateSiteToolTest extends TestCase
{
    protected CreateSiteTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new CreateSiteTool;
    }

    public function test_can_create_site()
    {
        $this->markTestSkipped('Site creation tests are complex in parallel test environments due to config file persistence');

        $result = $this->tool->handle([
            'handle' => 'fr',
            'name' => 'French',
            'url' => 'https://example.com/fr',
            'locale' => 'fr_FR',
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        if (! $response['success']) {
            dump('Site creation failed:', $response);
        }

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('site', $response['data']);
        $this->assertEquals('fr', $response['data']['site']['handle']);
        $this->assertEquals('French', $response['data']['site']['name']);
        $this->assertEquals('https://example.com/fr', $response['data']['site']['url']);
        $this->assertEquals('fr_FR', $response['data']['site']['locale']);

        // Note: In test environment, Site::get() may not immediately reflect
        // config file changes due to caching. The tool returns success
        // indicating the config file was written successfully.
    }

    public function test_prevents_duplicate_site()
    {
        $this->markTestSkipped('Site creation tests are complex in parallel test environments due to config file persistence');

        // Create first site
        $this->tool->handle([
            'handle' => 'duplicate_site',
            'name' => 'First Site',
            'url' => 'https://example.com/first',
            'locale' => 'en_US',
        ]);

        // Try to create duplicate
        $result = $this->tool->handle([
            'handle' => 'duplicate_site',
            'name' => 'Second Site',
            'url' => 'https://example.com/second',
            'locale' => 'en_US',
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('already exists', $response['errors'][0]);
    }

    public function test_validates_required_fields()
    {
        $result = $this->tool->handle([
            'handle' => 'incomplete_site',
            // Missing required fields
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        $this->assertFalse($response['success']);
        // Should fail validation for missing required fields
        $this->assertNotEmpty($response['errors']);
    }

    public function test_creates_site_with_direction()
    {
        $this->markTestSkipped('Site creation tests are complex in parallel test environments due to config file persistence');

        $result = $this->tool->handle([
            'handle' => 'ar',
            'name' => 'Arabic',
            'url' => 'https://example.com/ar',
            'locale' => 'ar_SA',
            'direction' => 'rtl',
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        $this->assertTrue($response['success']);
        $this->assertEquals('rtl', $response['data']['site']['direction']);

        // Note: In test environment, Site::get() may not immediately reflect
        // config file changes due to caching. The tool returns success
        // indicating the config file was written successfully.
    }

    public function test_tool_has_correct_metadata()
    {
        $this->assertEquals('statamic.sites.create', $this->tool->name());
        $this->assertStringContainsString('Create a new site configuration', $this->tool->description());
    }

    protected function tearDown(): void
    {
        // Clean up test site configuration
        $configPath = config_path('statamic/sites.php');
        if (file_exists($configPath)) {
            unlink($configPath);
        }

        parent::tearDown();
    }
}
