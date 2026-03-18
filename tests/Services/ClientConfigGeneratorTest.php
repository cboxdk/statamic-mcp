<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Services\ClientConfigGenerator;

beforeEach(function () {
    $this->generator = new ClientConfigGenerator;
    $this->baseUrl = 'https://example.com';
    $this->token = 'test-token-abc123';
});

// ---------------------------------------------------------------------------
// forClaudeDesktop()
// ---------------------------------------------------------------------------

describe('forClaudeDesktop', function () {
    it('returns valid JSON structure with mcpServers key', function () {
        $config = $this->generator->forClaudeDesktop($this->baseUrl, $this->token);

        expect($config)->toBeArray()
            ->toHaveKey('mcpServers')
            ->and($config['mcpServers'])->toHaveKey('statamic')
            ->and($config['mcpServers']['statamic'])->toHaveKeys(['command', 'args']);
    });

    it('uses npx command with mcp-remote', function () {
        $config = $this->generator->forClaudeDesktop($this->baseUrl, $this->token);

        expect($config['mcpServers']['statamic']['command'])->toBe('npx');
        expect($config['mcpServers']['statamic']['args'][0])->toBe('mcp-remote');
    });

    it('includes the MCP endpoint URL in args', function () {
        $config = $this->generator->forClaudeDesktop($this->baseUrl, $this->token);

        $args = $config['mcpServers']['statamic']['args'];
        expect($args[1])->toContain('example.com')
            ->toContain('/mcp/statamic');
    });

    it('includes authorization header in args', function () {
        $config = $this->generator->forClaudeDesktop($this->baseUrl, $this->token);

        $args = $config['mcpServers']['statamic']['args'];
        expect($args)->toContain('--header');
        expect($args[3])->toContain('Bearer')
            ->toContain($this->token);
    });

    it('adds --allow-http flag for HTTP URLs', function () {
        $config = $this->generator->forClaudeDesktop('http://localhost:8080', $this->token);

        $args = $config['mcpServers']['statamic']['args'];
        expect($args)->toContain('--allow-http');
    });

    it('does not add --allow-http flag for HTTPS URLs', function () {
        $config = $this->generator->forClaudeDesktop($this->baseUrl, $this->token);

        $args = $config['mcpServers']['statamic']['args'];
        expect($args)->not->toContain('--allow-http');
    });
});

// ---------------------------------------------------------------------------
// forCursor()
// ---------------------------------------------------------------------------

describe('forCursor', function () {
    it('returns valid JSON structure with mcpServers key', function () {
        $config = $this->generator->forCursor($this->baseUrl, $this->token);

        expect($config)->toBeArray()
            ->toHaveKey('mcpServers')
            ->and($config['mcpServers'])->toHaveKey('statamic')
            ->and($config['mcpServers']['statamic'])->toHaveKeys(['url', 'headers']);
    });

    it('includes the MCP endpoint URL', function () {
        $config = $this->generator->forCursor($this->baseUrl, $this->token);

        expect($config['mcpServers']['statamic']['url'])
            ->toContain('example.com')
            ->toContain('/mcp/statamic');
    });

    it('includes authorization header', function () {
        $config = $this->generator->forCursor($this->baseUrl, $this->token);

        expect($config['mcpServers']['statamic']['headers']['Authorization'])
            ->toBe("Bearer {$this->token}");
    });
});

// ---------------------------------------------------------------------------
// forGeneric()
// ---------------------------------------------------------------------------

describe('forGeneric', function () {
    it('returns valid JSON structure with mcpServers key', function () {
        $config = $this->generator->forGeneric($this->baseUrl, $this->token);

        expect($config)->toBeArray()
            ->toHaveKey('mcpServers')
            ->and($config['mcpServers'])->toHaveKey('statamic')
            ->and($config['mcpServers']['statamic'])->toHaveKeys(['url', 'transport', 'headers']);
    });

    it('specifies streamable-http transport', function () {
        $config = $this->generator->forGeneric($this->baseUrl, $this->token);

        expect($config['mcpServers']['statamic']['transport'])->toBe('streamable-http');
    });

    it('includes the MCP endpoint URL', function () {
        $config = $this->generator->forGeneric($this->baseUrl, $this->token);

        expect($config['mcpServers']['statamic']['url'])
            ->toContain('example.com')
            ->toContain('/mcp/statamic');
    });

    it('includes authorization header', function () {
        $config = $this->generator->forGeneric($this->baseUrl, $this->token);

        expect($config['mcpServers']['statamic']['headers']['Authorization'])
            ->toBe("Bearer {$this->token}");
    });

    it('includes accept header', function () {
        $config = $this->generator->forGeneric($this->baseUrl, $this->token);

        expect($config['mcpServers']['statamic']['headers']['Accept'])
            ->toBe('application/json');
    });
});

// ---------------------------------------------------------------------------
// URL normalization
// ---------------------------------------------------------------------------

describe('URL normalization', function () {
    it('appends MCP path when not present', function () {
        $config = $this->generator->forGeneric('https://example.com', $this->token);

        expect($config['mcpServers']['statamic']['url'])
            ->toBe('https://example.com/mcp/statamic');
    });

    it('does not duplicate MCP path when already present', function () {
        $config = $this->generator->forGeneric('https://example.com/mcp/statamic', $this->token);

        expect($config['mcpServers']['statamic']['url'])
            ->toBe('https://example.com/mcp/statamic');
    });

    it('strips trailing slash before appending path', function () {
        $config = $this->generator->forGeneric('https://example.com/', $this->token);

        expect($config['mcpServers']['statamic']['url'])
            ->toBe('https://example.com/mcp/statamic');
    });
});
