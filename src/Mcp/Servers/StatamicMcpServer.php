<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Servers;

use Cboxdk\StatamicMcp\Mcp\Prompts\AgentEducationPrompt;
use Cboxdk\StatamicMcp\Mcp\Prompts\ToolUsageContractPrompt;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\AssetsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\ContentFacadeRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\GlobalsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\SystemRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\TermsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\UsersRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\System\DiscoveryTool;
use Cboxdk\StatamicMcp\Mcp\Tools\System\SchemaTool;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Tool;

class StatamicMcpServer extends Server
{
    protected string $name = 'Statamic MCP Server';

    protected string $version = '2.0.0';

    protected string $instructions = <<<'MARKDOWN'
        You are connected to a Statamic CMS site via MCP. Use these tools to manage content, blueprints, assets, users, and system settings.

        Available tools:
        - statamic-entries: List, read, create, update, delete, publish/unpublish entries
        - statamic-terms: Manage taxonomy terms
        - statamic-globals: Manage global sets and their values
        - statamic-blueprints: Inspect and manage content blueprints/schemas
        - statamic-structures: Manage collections, taxonomies, navigations, sites
        - statamic-assets: Manage asset containers and files
        - statamic-users: Manage users, roles, and groups
        - statamic-system: System info, health checks, cache management
        - statamic-discovery: Find the right tool for your intent
        - statamic-schema: Inspect tool parameters and usage

        When the user asks about their website content, pages, blog posts, or CMS data, use these tools to fetch real data. Start with statamic-discovery if unsure which tool to use.
    MARKDOWN;

    public int $defaultPaginationLength = 200;

    public int $maxPaginationLength = 200;

    /**
     * The tools that the server exposes.
     *
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        // === Specialized Content Router Tools (4) ===
        EntriesRouter::class,
        TermsRouter::class,
        GlobalsRouter::class,
        ContentFacadeRouter::class,

        // === Core System Router Tools (5) ===
        StructuresRouter::class,
        AssetsRouter::class,
        UsersRouter::class,
        SystemRouter::class,
        BlueprintsRouter::class,

        // === Agent Education Tools (2) ===
        DiscoveryTool::class,
        SchemaTool::class,
    ];

    /**
     * The prompts that the server exposes.
     *
     * @var array<int, class-string<Prompt>>
     */
    protected array $prompts = [
        AgentEducationPrompt::class,
        ToolUsageContractPrompt::class,
    ];

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

        // Only override global error/exception handlers in CLI/stdio context
        // to avoid hijacking Laravel's web error handling pipeline.
        if (app()->runningInConsole()) {
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
                    if ($output !== false && ! empty(trim($output))) {
                        $trimmed = trim($output);
                        $isJsonRpc = str_starts_with($trimmed, '{"jsonrpc"') || str_starts_with($trimmed, '{"id"');
                        if (! $isJsonRpc) {
                            fwrite(STDERR, "Captured output: $output\n");
                        }
                    }
                }
            });
        }

        // Suppress common PHP startup warnings
        if (function_exists('opcache_get_status')) {
            @opcache_get_status(false);
        }

        // Suppress Laravel deprecation warnings that might write to stdout
        if (class_exists('Illuminate\Support\Facades\Log')) {
            try {
                Log::getLogger();
            } catch (\Exception $e) {
                // Ignore logging setup errors
            }
        }
    }
}
