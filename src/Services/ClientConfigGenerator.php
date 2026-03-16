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
    public function forClaudeDesktop(string $baseUrl, string $token): array
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
    public function forClaudeCode(string $baseUrl, string $token): array
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
     * Generate CLI command for Claude Code.
     */
    public function cliForClaudeCode(string $baseUrl, string $token): string
    {
        $url = $this->normalizeUrl($baseUrl);

        return "claude mcp add statamic --transport http --url \"{$url}\" --header \"Authorization: Bearer {$token}\"";
    }

    /**
     * Generate configuration for Cursor IDE.
     *
     * @return array<string, mixed>
     */
    public function forCursor(string $baseUrl, string $token): array
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
    public function forChatGpt(string $baseUrl, string $token): array
    {
        return [
            'note' => 'ChatGPT requires OAuth 2.0 — Bearer tokens are not supported.',
            'endpoint' => $this->normalizeUrl($baseUrl),
            'auth' => 'OAuth 2.0 (configured in ChatGPT web app)',
        ];
    }

    /**
     * Generate configuration for Windsurf IDE.
     *
     * @return array<string, mixed>
     */
    public function forWindsurf(string $baseUrl, string $token): array
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
    public function forGeneric(string $baseUrl, string $token): array
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
     * @return array<string, array{name: string, description: string, method: string, cli_method: string|null, config_file: string|null}>
     */
    public function getAvailableClients(): array
    {
        return [
            'claude-desktop' => [
                'name' => 'Claude Desktop',
                'description' => 'Anthropic Claude desktop application',
                'method' => 'forClaudeDesktop',
                'cli_method' => null,
                'config_file' => 'claude_desktop_config.json',
            ],
            'claude-code' => [
                'name' => 'Claude Code',
                'description' => 'Claude Code CLI — terminal-based AI assistant',
                'method' => 'forClaudeCode',
                'cli_method' => 'cliForClaudeCode',
                'config_file' => '.mcp.json',
            ],
            'cursor' => [
                'name' => 'Cursor',
                'description' => 'AI-powered code editor with MCP support',
                'method' => 'forCursor',
                'cli_method' => null,
                'config_file' => '.cursor/mcp.json',
            ],
            'chatgpt' => [
                'name' => 'ChatGPT',
                'description' => 'OpenAI ChatGPT with MCP server integration',
                'method' => 'forChatGpt',
                'cli_method' => null,
                'config_file' => null,
            ],
            'windsurf' => [
                'name' => 'Windsurf',
                'description' => 'Codeium Windsurf code editor with MCP support',
                'method' => 'forWindsurf',
                'cli_method' => null,
                'config_file' => '~/.codeium/windsurf/mcp_config.json',
            ],
            'generic' => [
                'name' => 'Other',
                'description' => 'Standard MCP client with streamable HTTP transport',
                'method' => 'forGeneric',
                'cli_method' => null,
                'config_file' => null,
            ],
        ];
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
