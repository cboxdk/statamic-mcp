<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\Navigations\CreateNavigationTool;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Nav;

class CreateNavigationToolTest extends TestCase
{
    protected CreateNavigationTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new CreateNavigationTool;
    }

    public function test_can_create_navigation()
    {
        $result = $this->tool->handle([
            'handle' => 'main_nav',
            'title' => 'Main Navigation',
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('navigation', $response['data']);
        $this->assertEquals('main_nav', $response['data']['navigation']['handle']);
        $this->assertEquals('Main Navigation', $response['data']['navigation']['title']);

        // Verify navigation was actually created
        $nav = Nav::find('main_nav');
        $this->assertNotNull($nav);
        $this->assertEquals('Main Navigation', $nav->title());
    }

    public function test_prevents_duplicate_navigation()
    {
        // Create first navigation
        $this->tool->handle([
            'handle' => 'duplicate_nav',
            'title' => 'First Navigation',
        ]);

        // Try to create duplicate
        $result = $this->tool->handle([
            'handle' => 'duplicate_nav',
            'title' => 'Second Navigation',
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('already exists', $response['errors'][0]);
    }

    public function test_validates_invalid_sites()
    {
        $result = $this->tool->handle([
            'handle' => 'site_test_nav',
            'title' => 'Site Test Navigation',
            'sites' => ['invalid_site'],
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('Invalid site', $response['errors'][0]);
    }

    public function test_creates_navigation_with_options()
    {
        $result = $this->tool->handle([
            'handle' => 'options_nav',
            'title' => 'Options Navigation',
            'max_depth' => 3,
            'expects_root' => true,
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        $this->assertTrue($response['success']);
        $this->assertEquals(3, $response['data']['navigation']['max_depth']);
        $this->assertTrue($response['data']['navigation']['expects_root']);

        // Verify navigation was created with options
        $nav = Nav::find('options_nav');
        $this->assertNotNull($nav);
        $this->assertEquals(3, $nav->maxDepth());
        $this->assertTrue($nav->expectsRoot());
    }

    public function test_tool_has_correct_metadata()
    {
        $this->assertEquals('statamic.navigations.create', $this->tool->name());
        $this->assertStringContainsString('Create a new Statamic navigation', $this->tool->description());
    }

    protected function tearDown(): void
    {
        // Clean up test navigations
        $testHandles = ['main_nav', 'duplicate_nav', 'site_test_nav', 'options_nav'];

        foreach ($testHandles as $handle) {
            $nav = Nav::find($handle);
            if ($nav) {
                $nav->delete();
            }
        }

        parent::tearDown();
    }
}
