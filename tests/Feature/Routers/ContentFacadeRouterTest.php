<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\ContentFacadeRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Taxonomy;

class ContentFacadeRouterTest extends TestCase
{
    private ContentFacadeRouter $router;

    private string $testId;

    private string $collectionHandle;

    private string $taxonomyHandle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new ContentFacadeRouter;
        $this->testId = bin2hex(random_bytes(4));

        $this->collectionHandle = "articles-{$this->testId}";
        Collection::make($this->collectionHandle)
            ->title('Articles')
            ->save();

        $this->taxonomyHandle = "tags-{$this->testId}";
        Taxonomy::make($this->taxonomyHandle)
            ->title('Tags')
            ->save();
    }

    public function test_content_audit_empty(): void
    {
        $result = $this->router->execute([
            'action' => 'content_audit',
        ]);

        $this->assertTrue($result['success']);
        $data = $result['data'];
        $this->assertEquals('content_audit', $data['workflow']);
        $this->assertTrue($data['completed']);
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('total_entries', $data['summary']);
        $this->assertArrayHasKey('total_terms', $data['summary']);
        $this->assertArrayHasKey('quality_score', $data['summary']);
    }

    public function test_content_audit_with_entries(): void
    {
        // Create a test entry
        Entry::make()
            ->collection($this->collectionHandle)
            ->slug('test-article')
            ->data(['title' => 'Test Article'])
            ->save();

        $result = $this->router->execute([
            'action' => 'content_audit',
        ]);

        $this->assertTrue($result['success']);
        $this->assertGreaterThanOrEqual(1, $result['data']['summary']['total_entries']);
    }

    public function test_cross_reference_empty(): void
    {
        $result = $this->router->execute([
            'action' => 'cross_reference',
        ]);

        $this->assertTrue($result['success']);
        $data = $result['data'];
        $this->assertEquals('cross_reference', $data['workflow']);
        $this->assertTrue($data['completed']);
        $this->assertArrayHasKey('statistics', $data);
        $this->assertArrayHasKey('relationships', $data);
    }

    public function test_invalid_action(): void
    {
        $result = $this->router->execute([
            'action' => 'nonexistent',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown action', $result['errors'][0]);
    }
}
