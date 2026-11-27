<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Services;

/**
 * Generates MCP client configuration snippets for different AI platforms.
 *
 * Produces ready-to-use configuration arrays and CLI commands that users can
 * paste into their Claude Desktop, Claude Code CLI, Cursor IDE, or other MCP-compatible clients.
 */
class ClientConfigGenerator
{
    /**
     * Generate configuration for Claude Desktop.
     *
     * Claude Desktop only supports stdio transport in its config file.
     * Uses mcp-remote as a bridge to connect to streamable HTTP servers.
     *
     * @return array<string, mixed>
     */
    public function forClaudeDesktop(string $baseUrl, #[\SensitiveParameter] string $token): array
    {
        $url = $this->normalizeUrl($baseUrl);

        $args = [
            'mcp-remote',
            $url,
            '--header',
            "Authorization: Bearer {$token}",
        ];

        if (str_starts_with($url, 'http://')) {
            $args[] = '--allow-http';
        }

        return [
            'mcpServers' => [
                'statamic' => [
                    'command' => 'npx',
                    'args' => $args,
                ],
            ],
        ];
    }

    /**
     * Generate configuration for Claude Code CLI.
     *
     * @return array<string, mixed>
     */
    public function forClaudeCode(string $baseUrl, #[\SensitiveParameter] string $token): array
    {
        return [
            'mcpServers' => [
                'statamic' => [
                    'type' => 'http',
                    'url' => $this->normalizeUrl($baseUrl),
                    'headers' => [
                        'Authorization' => "Bearer {$token}",
                    ],
                ],
            ],
        ];
    }

    /**
     * Generate CLI command for Claude Code.
     */
    public function cliForClaudeCode(string $baseUrl, #[\SensitiveParameter] string $token): string
    {
        $url = $this->normalizeUrl($baseUrl);

        return 'claude mcp add --transport http statamic ' . escapeshellarg($url)
            . ' --header ' . escapeshellarg("Authorization: Bearer {$token}");
    }

    /**
     * Generate configuration for Cursor IDE.
     *
     * @return array<string, mixed>
     */
    public function forCursor(string $baseUrl, #[\SensitiveParameter] string $token): array
    {
        return [
            'mcpServers' => [
                'statamic' => [
                    'url' => $this->normalizeUrl($baseUrl),
                    'headers' => [
                        'Authorization' => "Bearer {$token}",
                    ],
                ],
            ],
        ];
    }

    /**
     * Generate configuration for ChatGPT / OpenAI.
     *
     * ChatGPT only supports OAuth 2.0 authentication via the web UI.
     * Bearer tokens are not supported. This returns the endpoint URL
     * for reference only — setup is done through the ChatGPT web app.
     *
     * @return array<string, mixed>
     */
    public function forChatGpt(string $baseUrl, #[\SensitiveParameter] string $token): array
    {
        return [
            'note' => __('statamic-mcp::messages.chatgpt_oauth'),
            'endpoint' => $this->normalizeUrl($baseUrl),
            'auth' => __('statamic-mcp::messages.chatgpt_auth'),
        ];
    }

    /**
     * Generate configuration for Windsurf IDE.
     *
     * @return array<string, mixed>
     */
    public function forWindsurf(string $baseUrl, #[\SensitiveParameter] string $token): array
    {
        return [
            'mcpServers' => [
                'statamic' => [
                    'serverUrl' => $this->normalizeUrl($baseUrl),
                    'headers' => [
                        'Authorization' => "Bearer {$token}",
                    ],
                ],
            ],
        ];
    }

    /**
     * Generate a generic MCP client configuration.
     *
     * @return array<string, mixed>
     */
    public function forGeneric(string $baseUrl, #[\SensitiveParameter] string $token): array
    {
        return [
            'mcpServers' => [
                'statamic' => [
                    'url' => $this->normalizeUrl($baseUrl),
                    'transport' => 'streamable-http',
                    'headers' => [
                        'Authorization' => "Bearer {$token}",
                        'Accept' => 'application/json',
                    ],
                ],
            ],
        ];
    }

    /**
     * Get a list of all supported MCP clients with descriptions.
     *
     * @return array<string, array{name: string, description: string, method: string, cli_method: string|null, config_file: string|null, modes: list<string>}>
     */
    public function getAvailableClients(): array
    {
        return [
            'claude-desktop' => [
                'name' => 'Claude Desktop',
                'description' => __('statamic-mcp::messages.clients.claude-desktop'),
                'method' => 'forClaudeDesktop',
                'cli_method' => null,
                'config_file' => 'claude_desktop_config.json',
                'modes' => ['oauth', 'bearer', 'stdio'],
            ],
            'claude-code' => [
                'name' => 'Claude Code',
                'description' => __('statamic-mcp::messages.clients.claude-code'),
                'method' => 'forClaudeCode',
                'cli_method' => 'cliForClaudeCode',
                'config_file' => '.mcp.json',
                'modes' => ['bearer', 'oauth', 'stdio'],
            ],
            'cursor' => [
                'name' => 'Cursor',
                'description' => __('statamic-mcp::messages.clients.cursor'),
                'method' => 'forCursor',
                'cli_method' => null,
                'config_file' => '.cursor/mcp.json',
                'modes' => ['bearer', 'oauth', 'stdio'],
            ],
            'chatgpt' => [
                'name' => 'ChatGPT',
                'description' => __('statamic-mcp::messages.clients.chatgpt'),
                'method' => 'forChatGpt',
                'cli_method' => null,
                'config_file' => null,
                'modes' => ['oauth'],
            ],
            'windsurf' => [
                'name' => 'Windsurf',
                'description' => __('statamic-mcp::messages.clients.windsurf'),
                'method' => 'forWindsurf',
                'cli_method' => null,
                'config_file' => '~/.codeium/windsurf/mcp_config.json',
                'modes' => ['bearer', 'stdio'],
            ],
            'vscode' => [
                'name' => 'VS Code',
                'description' => __('statamic-mcp::messages.clients.vscode'),
                'method' => 'forVsCode',
                'cli_method' => null,
                'config_file' => '.vscode/mcp.json',
                'modes' => ['bearer', 'oauth', 'stdio'],
            ],
            'codex' => [
                'name' => 'Codex',
                'description' => __('statamic-mcp::messages.clients.codex'),
                'method' => 'forCodex',
                'cli_method' => null,
                'config_file' => '~/.codex/config.toml',
                'modes' => ['bearer', 'oauth', 'stdio'],
            ],
            'generic' => [
                'name' => __('statamic-mcp::messages.other_client'),
                'description' => __('statamic-mcp::messages.clients.generic'),
                'method' => 'forGeneric',
                'cli_method' => null,
                'config_file' => null,
                'modes' => ['bearer', 'stdio'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function forVsCode(string $baseUrl, #[\SensitiveParameter] string $token): array
    {
        return ['servers' => ['statamic' => [
            'type' => 'http', 'url' => $this->normalizeUrl($baseUrl),
            'headers' => ['Authorization' => "Bearer {$token}"],
        ]]];
    }

    /** @return array<string, mixed> */
    public function forCodex(string $baseUrl, #[\SensitiveParameter] string $token): array
    {
        return ['mcp_servers' => ['statamic' => [
            'url' => $this->normalizeUrl($baseUrl),
            'http_headers' => ['Authorization' => "Bearer {$token}"],
        ]]];
    }

    /**
     * Generate a copyable snippet without ever receiving a credential from the CP.
     * The browser fills the placeholder from its one-time token response.
     *
     * @return array<string, mixed>
     */
    public function snippet(string $client, string $mode, string $baseUrl): array
    {
        $info = $this->getAvailableClients()[$client] ?? null;
        if ($info === null || ! in_array($mode, $info['modes'], true)) {
            throw new \InvalidArgumentException(__('statamic-mcp::messages.unsupported_client'));
        }
        $url = $this->normalizeUrl($baseUrl);
        $map = match ($client) {
            'vscode' => 'servers',
            'codex' => 'mcp_servers',
            default => 'mcpServers',
        };
        $cli = null;
        $configFile = $info['config_file'];
        if ($mode === 'stdio') {
            $server = ['command' => 'php', 'args' => ['/path/to/site/artisan', 'mcp:start', 'statamic']];
            if (in_array($client, ['claude-code', 'vscode'], true)) {
                $server = ['type' => 'stdio', ...$server];
            }
            $config = [$map => ['statamic' => $server]];
            $cli = match ($client) {
                'claude-code' => 'claude mcp add --transport stdio statamic -- php /path/to/site/artisan mcp:start statamic',
                'codex' => 'codex mcp add statamic -- php /path/to/site/artisan mcp:start statamic',
                default => null,
            };
        } elseif ($mode === 'oauth') {
            $server = ['url' => $url];
            if (in_array($client, ['claude-code', 'vscode'], true)) {
                $server = ['type' => 'http', ...$server];
            }
            $config = in_array($client, ['chatgpt', 'claude-desktop'], true) ? null : [$map => ['statamic' => $server]];
            if ($config === null) {
                $configFile = null;
            }
            $cli = match ($client) {
                'claude-code' => 'claude mcp add --transport http statamic ' . escapeshellarg($url),
                'codex' => 'codex mcp add statamic --url ' . escapeshellarg($url) . "\ncodex mcp login statamic",
                default => null,
            };
        } else {
            $method = $info['method'];
            /** @var array<string, mixed> $config */
            $config = $this->{$method}($baseUrl, '<YOUR_TOKEN>');
            $cli = $client === 'claude-code' ? $this->cliForClaudeCode($baseUrl, '<YOUR_TOKEN>') : null;
        }

        $snippet = $config === null ? null : json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($client === 'codex') {
            $quote = fn (string $value): string => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $snippet = "[mcp_servers.statamic]\n" . ($mode === 'stdio'
                ? "command = \"php\"\nargs = [\"/path/to/site/artisan\", \"mcp:start\", \"statamic\"]"
                : 'url = ' . $quote($url) . ($mode === 'bearer' ? "\nhttp_headers = { Authorization = \"Bearer <YOUR_TOKEN>\" }" : ''));
        }

        return ['client' => $client, 'name' => $info['name'], 'description' => $info['description'],
            'mode' => $mode, 'endpoint' => $url, 'config' => $config, 'snippet' => $snippet,
            'format' => $client === 'codex' ? 'TOML' : 'JSON', 'config_file' => $configFile, 'cli' => $cli];
    }

    /**
     * Normalize the base URL to ensure it ends with the MCP path.
     */
    private function normalizeUrl(string $baseUrl): string
    {
        $baseUrl = rtrim($baseUrl, '/');

        /** @var string $mcpPath */
        $mcpPath = config('statamic.mcp.web.path', '/mcp/statamic');
        $mcpPath = '/' . ltrim($mcpPath, '/');

        if (! str_ends_with($baseUrl, $mcpPath)) {
            $baseUrl .= $mcpPath;
        }

        return $baseUrl;
    }
}
