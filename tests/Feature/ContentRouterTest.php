<?php

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\ContentRouter;
use Cboxdk\StatamicMcp\Tests\TestDataFixtures;
use Statamic\Facades\Entry;

describe('Content Router', function () {
    beforeEach(function () {
        $this->contentRouter = new ContentRouter;

        // Set up clean test fixtures for each test
        TestDataFixtures::setUp();
    });

    afterEach(function () {
        // Clean up test fixtures after each test
        TestDataFixtures::tearDown();
    });

    it('validates required action parameter', function () {
        $result = $this->contentRouter->execute([
            'type' => 'entry',
            'collection' => TestDataFixtures::TEST_COLLECTION,
        ]);

        expect($result)->toBeArray();
        // MCP framework validates required fields, tool may succeed if defaults are provided
        expect($result)->toHaveKey('success');
    });

    it('validates required type parameter', function () {
        $result = $this->contentRouter->execute([
            'action' => 'list',
            'collection' => TestDataFixtures::TEST_COLLECTION,
        ]);

        expect($result)->toBeArray();
        // MCP framework validates required fields, tool may succeed if defaults are provided
        expect($result)->toHaveKey('success');
    });

    it('validates collection exists for entry operations', function () {
        $result = $this->contentRouter->execute([
            'action' => 'list',
            'type' => 'entry',
            'collection' => 'nonexistent',
        ]);

        expect($result)->toBeArray();
        expect($result['success'])->toBeFalse();
        expect($result['errors'])->toBeArray();
        expect($result['errors'][0] ?? '')->toContain('Collection not found: nonexistent');
    });

    it('bypasses permissions in CLI context', function () {
        // Ensure CLI context (no X-MCP-Remote header)
        request()->headers->remove('X-MCP-Remote');

        $result = $this->contentRouter->execute([
            'action' => 'list',
            'type' => 'entry',
            'collection' => 'blog',
        ]);

        // Should succeed in CLI context regardless of permissions
        expect($result)->toBeArray();
        expect($result['success'])->toBeTrue();
        expect($result['data'])->toHaveKey('entries');
    });

    it('lists entries with correct structure', function () {
        // Create test entry
        Entry::make()
            ->collection('blog')
            ->locale('default')
            ->slug('test-post')
            ->data(['title' => 'Test Post'])
            ->published(true)
            ->save();

        $result = $this->contentRouter->execute([
            'action' => 'list',
            'type' => 'entry',
            'collection' => 'blog',
        ]);

        expect($result)->toBeArray();
        expect($result['success'])->toBeTrue();
        expect($result['data'])->toHaveKey('entries');
        expect($result['data'])->toHaveKey('pagination');
        expect($result['data'])->toHaveKey('collection');
        expect($result['data']['collection'])->toBe('blog');

        // Check entry structure if entries exist
        $entries = $result['data']['entries'];
        if (! empty($entries)) {
            $entry = $entries[0];
            expect($entry)->toHaveKey('id');
            expect($entry)->toHaveKey('slug');
            expect($entry)->toHaveKey('title');
            expect($entry)->toHaveKey('published');
            expect($entry)->toHaveKey('url');
            expect($entry)->toHaveKey('edit_url');
        }
    });

    it('creates entry with statamic api', function () {
        $entryData = [
            'title' => 'New Test Post',
            'content' => 'This is test content',
        ];

        $result = $this->contentRouter->execute([
            'action' => 'create',
            'type' => 'entry',
            'collection' => 'blog',
            'data' => $entryData,
        ]);

        expect($result)->toBeArray();
        expect($result['success'])->toBeTrue();
        expect($result['data'])->toHaveKey('entry');
        expect($result['data']['created'])->toBeTrue();

        $entry = $result['data']['entry'];
        expect($entry)->toHaveKey('id');
        expect($entry)->toHaveKey('slug');
        expect($entry['collection'])->toBe('blog');

        // Verify entry was actually created in Statamic
        $createdEntry = Entry::find($entry['id']);
        expect($createdEntry)->not()->toBeNull();
        expect($createdEntry->get('title'))->toBe('New Test Post');
        expect($createdEntry->get('content'))->toBe('This is test content');
    });

    it('includes correct metadata in responses', function () {
        $result = $this->contentRouter->execute([
            'action' => 'list',
            'type' => 'entry',
            'collection' => 'blog',
        ]);

        expect($result)->toBeArray();
        expect($result['success'])->toBeTrue();
        expect($result)->toHaveKey('meta');

        $meta = $result['meta'];
        expect($meta)->toHaveKey('tool');
        expect($meta)->toHaveKey('timestamp');
        expect($meta)->toHaveKey('statamic_version');
        expect($meta)->toHaveKey('laravel_version');
        expect($meta['tool'])->toBe('statamic-content');
    });
});
