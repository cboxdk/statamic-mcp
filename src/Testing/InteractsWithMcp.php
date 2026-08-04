<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Testing;

use Cboxdk\StatamicMcp\Mcp\Servers\StatamicMcpServer;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Server\Testing\TestResponse;
use Laravel\Mcp\Server\Tool;

/**
 * Drives this addon's MCP server from a test.
 *
 * Compose it into a TestCase to call tools and resources over the real protocol
 * — JSON-RPC argument delivery, response serialization, and the `isError` flag
 * all included — rather than calling `execute()` and skipping the layer clients
 * actually talk to.
 *
 * Requires `Laravel\Mcp\Server\McpServiceProvider` to be registered. Testbench
 * does not run package auto-discovery, and without that provider
 * `Laravel\Mcp\Request` receives no arguments at all, so every call silently
 * behaves as if it were made with none.
 */
trait InteractsWithMcp
{
    /**
     * Call a tool over the protocol.
     *
     * @param  class-string<Tool>|Tool  $tool
     * @param  array<string, mixed>  $arguments
     */
    protected function mcpTool(string|Tool $tool, array $arguments = []): TestResponse
    {
        return StatamicMcpServer::tool($tool, $arguments);
    }

    /**
     * Read a resource over the protocol.
     *
     * @param  class-string<\Laravel\Mcp\Server\Resource>|\Laravel\Mcp\Server\Resource  $resource
     * @param  array<string, mixed>  $uriVariables  Values for a URI-template resource
     */
    protected function mcpResource(string|Resource $resource, array $uriVariables = []): TestResponse
    {
        return StatamicMcpServer::resource($resource, $uriVariables);
    }

    /**
     * Render a prompt over the protocol.
     *
     * @param  class-string<Prompt>|Prompt  $prompt
     * @param  array<string, mixed>  $arguments
     */
    protected function mcpPrompt(string|Prompt $prompt, array $arguments = []): TestResponse
    {
        return StatamicMcpServer::prompt($prompt, $arguments);
    }

    /**
     * Bind a no-op transport so the server can be resolved directly.
     *
     * The tool and resource helpers supply their own transport; this is only
     * needed by tests that reach for the server instance itself.
     */
    protected function fakeMcpTransport(): void
    {
        $this->app?->bind(Transport::class, fn (): Transport => new FakeTransport);
    }
}
