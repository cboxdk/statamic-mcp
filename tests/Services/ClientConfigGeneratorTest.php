<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Services\ClientConfigGenerator;

beforeEach(function () {
    $this->generator = new ClientConfigGenerator;
    $this->baseUrl = 'https://example.com';
    $this->token = 'test-token-abc123';
});

it('uses the native client map, transport and secret header for every advertised mode', function () {
    foreach ($this->generator->getAvailableClients() as $client => $info) {
        foreach ($info['modes'] as $mode) {
            $result = $this->generator->snippet($client, $mode, $this->baseUrl);
            if ($mode === 'oauth' && in_array($client, ['claude-desktop', 'chatgpt'], true)) {
                expect($result['config'])->toBeNull()->and($result['config_file'])->toBeNull();
                continue;
            }
            $map = match ($client) {
                'vscode' => 'servers', 'codex' => 'mcp_servers', default => 'mcpServers'
            };
            $server = $result['config'][$map]['statamic'];
            if ($mode === 'stdio') {
                expect($server['command'])->toBe('php')
                    ->and($server['args'])->toBe(['/path/to/site/artisan', 'mcp:start', 'statamic'])
                    ->and($result['snippet'])->not->toContain('Bearer', 'https://');
            } elseif ($client !== 'claude-desktop') {
                expect($server[$client === 'windsurf' ? 'serverUrl' : 'url'])->toBe('https://example.com/mcp/statamic');
                if ($mode === 'bearer') {
                    expect($server[$client === 'codex' ? 'http_headers' : 'headers']['Authorization'])->toBe('Bearer <YOUR_TOKEN>');
                } else {
                    expect($result['snippet'])->not->toContain('Authorization', '<YOUR_TOKEN>');
                }
            }
            if (in_array($client, ['claude-code', 'vscode'], true)) {
                expect($server['type'])->toBe($mode === 'stdio' ? 'stdio' : 'http');
            }
            if ($client !== 'codex') {
                expect(json_decode($result['snippet'], true, flags: JSON_THROW_ON_ERROR))->toBe($result['config']);
            } else {
                expect($result['format'])->toBe('TOML')->and($result['snippet'])->toStartWith('[mcp_servers.statamic]');
            }
        }
    }
});

it('quotes shell arguments and uses positional HTTP URL for Claude Code', function () {
    $url = 'https://example.com/a\'b';
    $command = $this->generator->cliForClaudeCode($url, 'a\'b$(echo unsafe)');
    expect($command)->toBe("claude mcp add --transport http statamic 'https://example.com/a'\\''b/mcp/statamic' --header 'Authorization: Bearer a'\\''b$(echo unsafe)'");
    expect($this->generator->snippet('claude-code', 'oauth', $this->baseUrl)['cli'])
        ->toBe("claude mcp add --transport http statamic 'https://example.com/mcp/statamic'");
});

it('refuses modes the client does not support', function () {
    $this->generator->snippet('chatgpt', 'bearer', $this->baseUrl);
})->throws(InvalidArgumentException::class);

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
