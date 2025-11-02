<?php

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\ContentRouter;
use Illuminate\Support\Facades\Config;
use Statamic\Facades\Collection;

describe('Content Router Permissions', function () {
    beforeEach(function () {
        $this->contentRouter = new ContentRouter;

        // Create test collection
        Collection::make('blog')
            ->title('Blog')
            ->save();

        // Enable web mode for permission testing
        Config::set('statamic-mcp.tools.statamic-content.web_enabled', true);
    });

    it('requires authentication in web context', function () {
        // Mock web context
        request()->headers->set('X-MCP-Remote', 'true');

        // No authenticated user
        auth()->logout();

        $result = $this->contentRouter->execute([
            'action' => 'list',
            'type' => 'entry',
            'collection' => 'blog',
        ]);

        expect($result)->toBeArray();
        expect($result['success'])->toBeFalse();
        expect($result['errors'][0])->toContain('Permission denied');
    });

    it('bypasses all permissions in cli context', function () {
        // Ensure CLI context (no X-MCP-Remote header)
        request()->headers->remove('X-MCP-Remote');
        Config::set('statamic-mcp.security.force_web_mode', false);

        // No authenticated user
        auth()->logout();

        $result = $this->contentRouter->execute([
            'action' => 'list',
            'type' => 'entry',
            'collection' => 'blog',
        ]);

        // Should succeed in CLI context regardless of authentication/permissions
        expect($result)->toBeArray();
        expect($result['success'])->toBeTrue();
        expect($result['data'])->toHaveKey('entries');
    });

    it('respects force web mode configuration', function () {
        // Force web mode even in CLI
        Config::set('statamic-mcp.security.force_web_mode', true);

        // Remove web headers but force web mode
        request()->headers->remove('X-MCP-Remote');

        // No authenticated user
        auth()->logout();

        $result = $this->contentRouter->execute([
            'action' => 'list',
            'type' => 'entry',
            'collection' => 'blog',
        ]);

        // Should fail because web mode is forced and no authentication
        expect($result)->toBeArray();
        expect($result['success'])->toBeFalse();
        expect($result['errors'][0])->toContain('Permission denied');
    });

    it('rejects when web tool is disabled', function () {
        // Disable web tool
        Config::set('statamic-mcp.tools.statamic-content.web_enabled', false);

        // Mock web context
        request()->headers->set('X-MCP-Remote', 'true');

        $result = $this->contentRouter->execute([
            'action' => 'list',
            'type' => 'entry',
            'collection' => 'blog',
        ]);

        expect($result)->toBeArray();
        expect($result['success'])->toBeFalse();
        expect($result['errors'][0])->toContain('disabled for web access');
    });

    it('validates different permission patterns for different actions', function () {
        // Test in web context to trigger permission checking
        request()->headers->set('X-MCP-Remote', 'true');
        auth()->logout();

        $testCases = [
            ['action' => 'list', 'type' => 'entry', 'collection' => 'blog'],
            ['action' => 'create', 'type' => 'entry', 'collection' => 'blog', 'data' => ['title' => 'Test']],
            ['action' => 'get', 'type' => 'entry', 'collection' => 'blog', 'id' => 'test-id'],
        ];

        foreach ($testCases as $arguments) {
            $result = $this->contentRouter->execute($arguments);

            expect($result)->toBeArray();
            expect($result['success'])->toBeFalse();
            expect($result['errors'][0])->toContain('Permission denied');
        }
    });
});
