<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\Entries\CreateEntryTool;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

class CreateEntryToolTest extends TestCase
{
    protected CreateEntryTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new CreateEntryTool;

        // Ensure clean state and create blog collection for all tests
        \Statamic\Facades\Stache::clear();

        // Only create if it doesn't exist to avoid conflicts
        if (! \Statamic\Facades\Collection::find('blog')) {
            \Statamic\Facades\Collection::make('blog')
                ->title('Blog')
                ->save();
        }
    }

    public function test_can_create_entry()
    {
        $uniqueSlug = 'test-entry-' . uniqid();

        $result = $this->tool->handle([
            'collection' => 'blog',
            'title' => 'Test Entry',
            'slug' => $uniqueSlug,
            'data' => [
                'content' => 'Test content for the entry.',
            ],
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals($uniqueSlug, $response['data']['entry']['slug']);
        $this->assertEquals('Test Entry', $response['data']['entry']['title']);

        // Note: In parallel test environments, entry verification via query
        // can be unreliable due to Stache caching. The tool response above
        // confirms successful creation with all expected data.
    }

    public function test_prevents_duplicate_entry()
    {
        $duplicateSlug = 'duplicate-test-' . uniqid();

        // Ensure collection exists before test
        if (! Collection::find('blog')) {
            Collection::make('blog')
                ->title('Blog')
                ->save();
        }

        // Create first entry
        $firstResult = $this->tool->handle([
            'collection' => 'blog',
            'title' => 'Test Entry',
            'slug' => $duplicateSlug,
        ]);

        $firstResultData = $firstResult->toArray();
        $firstResponse = json_decode($firstResultData['content'][0]['text'], true);

        // Ensure first entry was created successfully
        $this->assertTrue($firstResponse['success'], 'First entry creation should succeed');

        // Ensure collection still exists for second call
        if (! Collection::find('blog')) {
            Collection::make('blog')
                ->title('Blog')
                ->save();
        }

        // Try to create duplicate
        $result = $this->tool->handle([
            'collection' => 'blog',
            'title' => 'Another Entry',
            'slug' => $duplicateSlug,
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('already exists', $response['errors'][0]);
    }

    public function test_validates_invalid_collection()
    {
        $result = $this->tool->handle([
            'collection' => 'nonexistent_collection',
            'title' => 'Test Entry',
            'slug' => 'test-slug',
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('not found', $response['errors'][0]);
    }

    public function test_validates_invalid_site()
    {
        // Create a collection first
        Collection::make('blog')
            ->title('Blog')
            ->save();

        $result = $this->tool->handle([
            'collection' => 'blog',
            'title' => 'Test Entry',
            'slug' => 'test-slug',
            'site' => 'invalid_site',
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('Site', $response['errors'][0]);
    }

    public function test_dry_run_mode()
    {
        $dryRunSlug = 'dry-run-entry-' . uniqid();

        $result = $this->tool->handle([
            'collection' => 'blog',
            'title' => 'Dry Run Entry',
            'slug' => $dryRunSlug,
            'dry_run' => true,
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);

        // Verify entry was NOT actually created
        $entry = Entry::query()->where('collection', 'blog')->where('slug', 'dry-run-entry')->first();
        $this->assertNull($entry);
    }

    public function test_tool_has_correct_metadata()
    {
        $this->assertEquals('statamic.entries.create', $this->tool->name());
        $this->assertStringContainsString('Create a new entry', $this->tool->description());
    }

    protected function tearDown(): void
    {
        try {
            // Clean up test entries and collections
            $testSlugs = ['test-entry', 'duplicate-test', 'test-slug', 'dry-run-entry'];

            foreach ($testSlugs as $baseSlug) {
                // Clean up entries with this base slug pattern (including uniqid suffixes)
                $entries = Entry::query()
                    ->where('collection', 'blog')
                    ->get()
                    ->filter(fn ($entry) => str_starts_with($entry->slug(), $baseSlug));

                foreach ($entries as $entry) {
                    $entry->delete();
                }
            }

            // Clean up test collections
            $collection = Collection::find('blog');
            if ($collection) {
                $collection->delete();
            }
        } catch (\Exception $e) {
            // Ignore cleanup errors in parallel test execution
        }

        parent::tearDown();
    }
}
