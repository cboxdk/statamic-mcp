<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Servers;

use Cboxdk\StatamicMcp\Mcp\Prompts\AgentEducationPrompt;
use Cboxdk\StatamicMcp\Mcp\Prompts\ToolUsageContractPrompt;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\AssetsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\ContentFacadeRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\ContentRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\GlobalsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\SystemRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\TermsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\UsersRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\System\DiscoveryTool;
use Cboxdk\StatamicMcp\Mcp\Tools\System\SchemaTool;
use Laravel\Mcp\Server;

class StatamicMcpServer extends Server
{
    public int $defaultPaginationLength = 200;

    public int $maxPaginationLength = 200;

    /**
     * The tools that the server exposes.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    public array $tools = [
        // === Specialized Content Router Tools (5) ===
        // Domain-specific routers for focused content management
        EntriesRouter::class,        // Collection entry management with publication control
        TermsRouter::class,          // Taxonomy term management with relationship tracking
        GlobalsRouter::class,        // Global sets and site-wide configuration
        ContentFacadeRouter::class,  // High-level content workflows and orchestration
        ContentRouter::class,        // Legacy content router (deprecated - will be removed)

        // === Core System Router Tools (4) ===
        // Each router consolidates multiple related operations into a single tool
        StructuresRouter::class,     // Collections, taxonomies, navigations, sites
        AssetsRouter::class,         // Asset containers and asset operations
        UsersRouter::class,          // Users, roles, user groups management
        SystemRouter::class,         // Cache, health, config, system operations
        BlueprintsRouter::class,     // Blueprint operations (kept separate for complexity)

        // === Agent Education Tools (2) ===
        // Tools for agent discovery and schema exploration
        DiscoveryTool::class,        // Intent-based tool discovery and recommendations
        SchemaTool::class,           // Detailed tool schema inspection and documentation
    ];

    /**
     * The prompts that the server exposes.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Prompt>>
     */
    public array $prompts = [];

    /**
     * Initialize the server with context-aware configuration.
     */
    public function __construct(\Laravel\Mcp\Server\Contracts\Transport $transport)
    {
        parent::__construct($transport);

        // Only expose prompts for local/CLI context, not web
        if ($this->isLocalContext()) {
            $this->prompts = [
                // Agent Education Prompts (CLI only)
                AgentEducationPrompt::class,
                ToolUsageContractPrompt::class,
            ];
        }
    }

    /**
     * Get the server name.
     */
    public function name(): string
    {
        return 'Statamic MCP Server';
    }

    /**
     * Get the server description.
     */
    public function description(): string
    {
        return 'Revolutionary MCP server for Statamic development with specialized router architecture and agent education system. Features 9 domain-specific routers + 2 specialized tools with self-documenting interfaces, intent-based discovery, and safety-first protocols for comprehensive CMS management.';
    }

    /**
     * Get the server version.
     */
    public function version(): string
    {
        return '0.1.0-alpha';
    }

    /**
     * Boot the MCP server with proper error handling.
     */
    public function boot(): void
    {
        parent::boot();

        // Redirect Laravel error output to stderr to prevent JSON contamination
        $this->setupErrorHandling();
    }

    /**
     * Setup error handling to prevent stdout contamination.
     */
    protected function setupErrorHandling(): void
    {
        // Capture and redirect Laravel error output to stderr
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        ini_set('error_log', 'php://stderr');

        // Set custom error handler to ensure errors go to stderr
        set_error_handler(function ($severity, $message, $file, $line) {
            if (error_reporting() & $severity) {
                fwrite(STDERR, "Error: {$message} in {$file} on line {$line}\n");
            }

            return true;
        });

        // Set custom exception handler
        set_exception_handler(function ($exception) {
            fwrite(STDERR, 'Exception: ' . $exception->getMessage() . "\n");
            fwrite(STDERR, 'File: ' . $exception->getFile() . ' Line: ' . $exception->getLine() . "\n");
            fwrite(STDERR, "Stack trace:\n" . $exception->getTraceAsString() . "\n");
        });

        // Capture and redirect output buffer to prevent contamination
        if (ob_get_level() === 0) {
            ob_start();
        }

        // Register shutdown function to clean up any remaining output
        register_shutdown_function(function () {
            while (ob_get_level() > 0) {
                $output = ob_get_clean();
                if ($output !== false && ! empty(trim($output)) && ! $this->isJsonRpc($output)) {
                    fwrite(STDERR, "Captured output: $output\n");
                }
            }
        });

        // Suppress common PHP startup warnings
        if (function_exists('opcache_get_status')) {
            @opcache_get_status(false);
        }

        // Suppress Laravel deprecation warnings that might write to stdout
        if (class_exists('Illuminate\Support\Facades\Log')) {
            try {
                \Illuminate\Support\Facades\Log::getLogger();
            } catch (\Exception $e) {
                // Ignore logging setup errors
            }
        }
    }

    /**
     * Check if output looks like JSON-RPC.
     */
    private function isJsonRpc(string $output): bool
    {
        $trimmed = trim($output);

        return str_starts_with($trimmed, '{"jsonrpc"') || str_starts_with($trimmed, '{"id"');
    }

    /**
     * Detect if running in local/CLI context vs web context.
     */
    private function isLocalContext(): bool
    {
        // Check if running via artisan command (local/CLI)
        if (app()->runningInConsole()) {
            return true;
        }

        // Check if we're in HTTP context with web MCP middleware
        try {
            $request = request();
            if ($request->hasHeader('Authorization')) {
                return false; // Web MCP request
            }
        } catch (\Exception $e) {
            // Ignore request errors in CLI context
        }

        // Check for stdio transport (typically local MCP)
        if (php_sapi_name() === 'cli') {
            return true;
        }

        // Default to local for safety
        return true;
    }
}
