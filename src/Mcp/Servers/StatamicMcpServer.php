<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Servers;

use Cboxdk\StatamicMcp\Mcp\Prompts\AgentEducationPrompt;
use Cboxdk\StatamicMcp\Mcp\Prompts\ToolUsageContractPrompt;
use Cboxdk\StatamicMcp\Mcp\Resources\BlueprintIndexResource;
use Cboxdk\StatamicMcp\Mcp\Resources\BlueprintResource;
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
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Enums\CacheScope;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Cacheable;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolSearch;

class StatamicMcpServer extends Server
{
    protected string $name = 'Statamic MCP Server';

    /**
     * Reported to clients in the initialize handshake. Read from the installed
     * package rather than hardcoded, so it cannot drift at the next release.
     */
    protected string $version = self::FALLBACK_VERSION;

    /** Used when the package metadata cannot be read (e.g. a non-Composer checkout). */
    private const FALLBACK_VERSION = '0.0.0';

    private const PACKAGE_NAME = 'cboxdk/statamic-mcp';

    protected string $instructions = '';

    /**
     * What every client is told, whichever catalog shape it gets.
     */
    private const BASE_INSTRUCTIONS = <<<'MARKDOWN'
        You are connected to a Statamic CMS site via MCP. Use these tools to read and manage
        content, blueprints, assets, users, and system settings. They return real site data —
        never guess at a handle, a field name, or a value you could look up.

        Before any create or update, read the target's blueprint first — statamic-blueprints get,
        or the statamic://blueprints resources. The write tools validate against it and will
        reject a payload shaped by guesswork.
        MARKDOWN;

    public int $defaultPaginationLength = 200;

    public int $maxPaginationLength = 200;

    /**
     * Tools every client loads at connect time.
     *
     * The routers carry large schemas — the full catalog is ~31 KB, which each
     * client pays for on every connection before it has asked anything. These
     * three cover the work a session actually starts with; the rest are reached
     * through search_tools and execute_tools, which cuts the catalog by ~60%.
     *
     * @var array<int, class-string<Tool>>
     */
    public const CORE_TOOLS = [
        DiscoveryTool::class,
        EntriesRouter::class,
        BlueprintsRouter::class,
    ];

    /**
     * Tools reached through search_tools and execute_tools rather than the catalog.
     *
     * Still fully gated: ToolSearch honours shouldRegister(), and execute_tools
     * invokes the tool's own handle(), so token scope, resource policy, Statamic
     * permissions and the confirmation gate all apply exactly as on a direct call.
     *
     * @var array<int, class-string<Tool>>
     */
    public const SEARCHABLE_TOOLS = [
        TermsRouter::class,
        GlobalsRouter::class,
        ContentFacadeRouter::class,
        StructuresRouter::class,
        AssetsRouter::class,
        UsersRouter::class,
        SystemRouter::class,
        SchemaTool::class,
    ];

    /**
     * Every tool this server offers.
     *
     * Kept flat and complete. createContext() decides how they are *presented*
     * to a client, but anything asking this server what it can do — tests,
     * tooling, the dashboard — should get all of them, not the catalog shape.
     *
     * @var array<int|string, class-string<Tool>|array<int, class-string<Tool>>>
     */
    protected array $tools = [
        ...self::CORE_TOOLS,
        ...self::SEARCHABLE_TOOLS,
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
     * The resources that the server exposes.
     *
     * Read-only views of the site's schema, so a client can look up field
     * structure without spending a tool call. They enforce the same token
     * scopes, resource policy, and Statamic permissions the tools do.
     *
     * @var array<int, class-string<Server\Resource>>
     */
    protected array $resources = [
        BlueprintIndexResource::class,
        BlueprintResource::class,
    ];

    /**
     * How long a client may reuse a response instead of asking again.
     *
     * Only the catalog is worth hinting at. It is derived from config —
     * per-domain tool enablement — so it changes on deploy, not during a
     * session, and the same list is served to every token.
     *
     * Everything else is left at the library default of "do not cache".
     * Blueprint reads carry their own, shorter hint on the resource itself;
     * tool calls are never cacheable, which is what keeps content reads fresh.
     *
     * Scope stays private throughout: these responses are filtered by the
     * caller's token scope, resource policy and Statamic permissions, so no
     * shared cache may ever hand one caller's view to another.
     *
     * @return array<string, Cacheable>
     */
    protected function cacheHints(): array
    {
        return [
            'server/discover' => new Cacheable(ttlMs: 300_000, scope: CacheScope::Private),
            'tools/list' => new Cacheable(ttlMs: 300_000, scope: CacheScope::Private),
            'prompts/list' => new Cacheable(ttlMs: 300_000, scope: CacheScope::Private),
        ];
    }

    /**
     * Build the context each request is answered from.
     *
     * The tool catalog and the instructions describing it are decided here
     * rather than in a property initializer, because both depend on config the
     * operator can change: a client that handles search_tools badly can be given
     * the whole catalog instead. Doing it in createContext() rather than boot()
     * keeps the two in step no matter how the server was started.
     */
    public function createContext(): ServerContext
    {
        $searchable = $this->searchableCatalogEnabled();

        $this->instructions = self::BASE_INSTRUCTIONS . "\n\n" . ($searchable
            ? $this->searchableCatalogInstructions()
            : 'Every tool is listed in this catalog; call them directly.');

        if (! $searchable) {
            return parent::createContext();
        }

        // Reshape for the wire only, then put the property back: $tools is the
        // honest list of what this server offers, and callers read it.
        $all = $this->tools;
        $this->tools = [...self::CORE_TOOLS, ToolSearch::class => self::SEARCHABLE_TOOLS];

        try {
            return parent::createContext();
        } finally {
            $this->tools = $all;
        }
    }

    /**
     * Whether the rarely-used tools are kept out of the catalog.
     *
     * On by default: the full catalog is ~31 KB, which every client pays for on
     * every connection before it has asked anything. Operators whose client
     * handles search_tools poorly can opt out and get all of them listed.
     */
    private function searchableCatalogEnabled(): bool
    {
        return (bool) config('statamic.mcp.catalog.searchable', true);
    }

    /**
     * Tell the client the catalog is partial, and name what is missing.
     *
     * Without this an agent sees eight fewer tools and concludes the site
     * cannot do those things, rather than reaching for search_tools.
     */
    private function searchableCatalogInstructions(): string
    {
        $searchable = implode(', ', array_map(
            fn (string $class): string => app($class)->name(),
            self::SEARCHABLE_TOOLS,
        ));

        return <<<MARKDOWN
            This catalog is deliberately partial. These tools are not listed — find them with
            search_tools and run them with execute_tools: {$searchable}. They are fully
            available; only their schemas are withheld until you ask. statamic-system-discover
            names the tool for a given intent and tells you which of the two ways to reach it.
            MARKDOWN;
    }

    /**
     * Boot the MCP server with proper error handling.
     */
    public function boot(): void
    {
        $this->version = $this->resolvePackageVersion();

        parent::boot();

        // Redirect Laravel error output to stderr to prevent JSON contamination
        $this->setupErrorHandling();
    }

    /**
     * Resolve the addon's version from Composer's installed-package metadata.
     */
    private function resolvePackageVersion(): string
    {
        if (! class_exists(InstalledVersions::class)) {
            return self::FALLBACK_VERSION;
        }

        try {
            $version = InstalledVersions::getPrettyVersion(self::PACKAGE_NAME);
        } catch (\OutOfBoundsException) {
            // Package not installed under this name — a path repository or a
            // consumer that renamed it. Neither is worth failing the handshake.
            return self::FALLBACK_VERSION;
        }

        return $version ?? self::FALLBACK_VERSION;
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
                    $output = (string) ob_get_clean();
                    $trimmed = trim($output);
                    if ($trimmed !== '') {
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
