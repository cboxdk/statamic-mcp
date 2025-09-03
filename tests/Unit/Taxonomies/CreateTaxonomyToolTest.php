<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\Taxonomies\CreateTaxonomyTool;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Taxonomy;

class CreateTaxonomyToolTest extends TestCase
{
    protected CreateTaxonomyTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new CreateTaxonomyTool;
    }

    public function test_can_create_taxonomy()
    {
        $result = $this->tool->handle([
            'handle' => 'categories',
            'title' => 'Categories',
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('taxonomy', $response['data']);
        $this->assertEquals('categories', $response['data']['taxonomy']['handle']);
        $this->assertEquals('Categories', $response['data']['taxonomy']['title']);

        // Verify taxonomy was actually created
        $taxonomy = Taxonomy::find('categories');
        $this->assertNotNull($taxonomy);
        $this->assertEquals('Categories', $taxonomy->title());
    }

    public function test_prevents_duplicate_taxonomy()
    {
        // Create first taxonomy
        $this->tool->handle([
            'handle' => 'duplicate_taxonomy',
            'title' => 'First Taxonomy',
        ]);

        // Try to create duplicate
        $result = $this->tool->handle([
            'handle' => 'duplicate_taxonomy',
            'title' => 'Second Taxonomy',
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('already exists', $response['errors'][0]);
    }

    public function test_validates_invalid_sites()
    {
        $result = $this->tool->handle([
            'handle' => 'site_test_taxonomy',
            'title' => 'Site Test Taxonomy',
            'sites' => ['invalid_site'],
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('Invalid site', $response['errors'][0]);
    }

    public function test_creates_taxonomy_with_blueprint()
    {
        $result = $this->tool->handle([
            'handle' => 'tags_with_blueprint',
            'title' => 'Tags with Blueprint',
            'blueprint' => 'tag_blueprint',
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        if (! $response['success']) {
            dump('Taxonomy creation failed:', $response);
        }

        // Should succeed without blueprint since v5 doesn't use them
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('blueprint', $response['data']['taxonomy']);
        $this->assertNull($response['data']['taxonomy']['blueprint']); // Taxonomies don't have blueprints in v5

        // Verify taxonomy was created
        $taxonomy = Taxonomy::find('tags_with_blueprint');
        $this->assertNotNull($taxonomy);
    }

    public function test_tool_has_correct_metadata()
    {
        $this->assertEquals('statamic.taxonomies.create', $this->tool->name());
        $this->assertStringContainsString('Create a new Statamic taxonomy', $this->tool->description());
    }

    protected function tearDown(): void
    {
        // Clean up test taxonomies
        $testHandles = ['categories', 'duplicate_taxonomy', 'site_test_taxonomy', 'tags_with_blueprint'];

        foreach ($testHandles as $handle) {
            $taxonomy = Taxonomy::find($handle);
            if ($taxonomy) {
                $taxonomy->delete();
            }
        }

        parent::tearDown();
    }
}
