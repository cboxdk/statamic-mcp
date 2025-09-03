<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\Globals\CreateGlobalSetTool;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\GlobalSet;

class CreateGlobalSetToolTest extends TestCase
{
    protected CreateGlobalSetTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new CreateGlobalSetTool;
    }

    public function test_can_create_global_set()
    {
        $result = $this->tool->handle([
            'handle' => 'test_globals',
            'title' => 'Test Global Set',
            'initial_values' => [
                'company_name' => 'Test Company',
                'email' => 'test@example.com',
            ],
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('global_set', $response['data']);
        $this->assertEquals('test_globals', $response['data']['global_set']['handle']);
        $this->assertEquals('Test Global Set', $response['data']['global_set']['title']);
        $this->assertArrayHasKey('initial_values', $response['data']);
    }

    public function test_prevents_duplicate_global_set()
    {
        // Create first global set
        $this->tool->handle([
            'handle' => 'duplicate_test',
            'title' => 'First Set',
        ]);

        // Try to create duplicate
        $result = $this->tool->handle([
            'handle' => 'duplicate_test',
            'title' => 'Second Set',
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('already exists', $response['errors'][0]);
    }

    public function test_validates_invalid_sites()
    {
        $result = $this->tool->handle([
            'handle' => 'site_test',
            'title' => 'Site Test',
            'sites' => ['invalid_site'],
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('Invalid site handles', $response['errors'][0]);
    }

    public function test_handles_blueprint_reference()
    {
        $result = $this->tool->handle([
            'handle' => 'blueprint_test',
            'title' => 'Blueprint Test',
            'blueprint' => 'nonexistent_blueprint',
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('Blueprint', $response['errors'][0]);
    }

    public function test_tool_has_correct_metadata()
    {
        $this->assertEquals('statamic.globals.sets.create', $this->tool->name());
        $this->assertStringContainsString('Create a new global set', $this->tool->description());
    }

    protected function tearDown(): void
    {
        // Clean up test global sets
        $testHandles = ['test_globals', 'duplicate_test', 'site_test', 'blueprint_test'];

        foreach ($testHandles as $handle) {
            $globalSet = GlobalSet::findByHandle($handle);
            if ($globalSet) {
                $globalSet->delete();
            }
        }

        parent::tearDown();
    }
}
