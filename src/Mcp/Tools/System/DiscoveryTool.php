<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\System;

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Mcp\ExtraTools;
use Cboxdk\StatamicMcp\Mcp\Servers\StatamicMcpServer;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Cboxdk\StatamicMcp\Support\McpContext;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Contracts\Auth\User;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;

/**
 * Lightweight tool discovery for MCP agent orientation.
 *
 * Returns the current Statamic system state (collections, taxonomies, etc.)
 * and matches user intent to recommended tools. Designed for minimal token
 * overhead — the LLM handles reasoning, this tool provides data.
 */
#[IsReadOnly]
#[Name('statamic-system-discover')]
#[Title('Statamic Tool Discovery')]
#[Description('Discover Statamic MCP tools based on intent and get current system state for context-aware guidance.')]
class DiscoveryTool extends BaseStatamicTool
{
    /** @var list<string>|null */
    private ?array $catalogToolNames = null;

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        return [
            'intent' => JsonSchema::string()
                ->description('What you want to accomplish (e.g., "manage blog entries", "configure blueprints", "clear cache")')
                ->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function executeInternal(array $arguments): array
    {
        $intent = strtolower(is_string($arguments['intent'] ?? null) ? $arguments['intent'] : '');

        // In web context, require system:read scope before exposing system state
        if (McpContext::isWebRequest()) {
            /** @var McpTokenData|null $mcpToken */
            $mcpToken = request()->attributes->get('mcp_token');

            if ($mcpToken) {
                /** @var TokenService $tokenService */
                $tokenService = app(TokenService::class);

                if (! $tokenService->hasScope($mcpToken, TokenScope::SystemRead)) {
                    return [
                        'discovery' => [
                            'recommended_tools' => $this->matchTools($intent),
                            'available_tools' => $this->getToolSummary(),
                        ],
                    ];
                }
            }
        }

        return [
            'discovery' => [
                'recommended_tools' => $this->matchTools($intent),
                'system_state' => $this->getSystemState(),
                'available_tools' => $this->getToolSummary(),
            ],
        ];
    }

    /**
     * Match intent keywords to recommended tools, ordered by relevance.
     *
     * @return list<array{tool: string, reason: string, access: string}>
     */
    private function matchTools(string $intent): array
    {
        $toolPatterns = [
            'statamic-entries' => ['entry', 'entries', 'content', 'article', 'page', 'post', 'publish', 'unpublish', 'draft'],
            'statamic-terms' => ['term', 'terms', 'taxonomy', 'tag', 'category', 'categorize'],
            'statamic-globals' => ['global', 'globals', 'site settings', 'footer', 'header', 'seo'],
            'statamic-blueprints' => ['blueprint', 'field', 'schema', 'fieldset', 'fieldtype', 'scan'],
            'statamic-structures' => ['collection', 'navigation', 'nav', 'structure', 'taxonomy setup', 'site config'],
            'statamic-assets' => ['asset', 'file', 'image', 'media', 'upload', 'container', 'storage'],
            'statamic-users' => ['user', 'role', 'permission', 'auth', 'group', 'access'],
            'statamic-system' => ['cache', 'health', 'system', 'info', 'performance', 'maintenance', 'config'],
            'statamic-content-facade' => ['workflow', 'bulk', 'import', 'audit', 'setup collection', 'validate content', 'schema drift', 'broken', 'orphan'],
        ];

        $toolDescriptions = [
            'statamic-entries' => 'CRUD operations on entries (list, get, create, update, delete, publish, unpublish)',
            'statamic-terms' => 'CRUD operations on taxonomy terms',
            'statamic-globals' => 'Read and update global sets and their values',
            'statamic-blueprints' => 'Manage blueprints: list, get, create, update, delete, scan, generate, types, validate',
            'statamic-structures' => 'Manage collections, taxonomies, navigations, sites, and global sets',
            'statamic-assets' => 'Manage asset containers and files (list, get, upload, move, copy, delete)',
            'statamic-users' => 'Manage users, roles, and groups with permission assignment',
            'statamic-system' => 'System info, health checks, cache management, and configuration',
            'statamic-content-facade' => 'High-level workflows: content_audit, content_validate, cross_reference',
        ];

        $matches = [];

        foreach ($toolPatterns as $tool => $keywords) {
            $score = 0;
            foreach ($keywords as $keyword) {
                if (str_contains($intent, $keyword)) {
                    $score++;
                }
            }
            if ($score > 0) {
                $matches[] = [
                    'tool' => $tool,
                    'reason' => $toolDescriptions[$tool],
                    'access' => $this->accessPath($tool),
                    '_score' => $score,
                ];
            }
        }

        // Tools other packages registered bring their own keywords, scored the
        // same way. Without this an intent such as "seo" only ever reaches the
        // built-in routers, even on a site whose SEO package registered a tool.
        foreach ($this->extraTools() as $tool) {
            $score = 0;
            foreach ($tool->discoveryKeywords() as $keyword) {
                $keyword = strtolower(trim($keyword));
                if ($keyword !== '' && str_contains($intent, $keyword)) {
                    $score++;
                }
            }
            if ($score > 0) {
                $matches[] = [
                    'tool' => $tool->name(),
                    'reason' => $tool->discoveryReason(),
                    'access' => $this->accessPath($tool->name()),
                    '_score' => $score,
                ];
            }
        }

        // Sort by relevance (highest score first)
        usort($matches, fn (array $a, array $b): int => $b['_score'] <=> $a['_score']);

        // Remove internal score from output
        return array_map(function (array $match): array {
            unset($match['_score']);

            return $match;
        }, $matches);
    }

    /**
     * The tools other packages registered that a client is offered.
     *
     * Filtered by shouldRegister(), the check the catalog applies, so a tool an
     * operator switched off is never recommended. A tool that cannot be resolved
     * is skipped, as it is in catalogToolNames().
     *
     * @return list<BaseRouter>
     */
    private function extraTools(): array
    {
        $tools = [];

        foreach (array_keys(ExtraTools::all()) as $class) {
            try {
                $tool = app($class);
            } catch (\Throwable) {
                continue;
            }

            if ($tool instanceof BaseRouter && $tool->shouldRegister()) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    /**
     * How a client reaches a tool.
     *
     * Most tools are kept out of the catalog to keep its token cost down, so
     * naming one is not enough — a client that cannot see it needs telling that
     * it goes through execute_tools. Read from the server's own split rather
     * than restated here, so the two cannot drift apart.
     */
    private function accessPath(string $toolName): string
    {
        return in_array($toolName, $this->catalogToolNames(), true)
            ? 'listed_in_catalog'
            : 'via_execute_tools';
    }

    /**
     * The names of the tools a client already has in its catalog.
     *
     * Follows the same config the server follows when it builds the catalog.
     * When an operator turns the searchable catalog off, every tool is listed
     * and execute_tools does not exist — pointing a client at it there would
     * send it to call a tool that is not registered.
     *
     * @return list<string>
     */
    private function catalogToolNames(): array
    {
        if ($this->catalogToolNames !== null) {
            return $this->catalogToolNames;
        }

        $classes = config('statamic.mcp.catalog.searchable', true)
            ? [...StatamicMcpServer::CORE_TOOLS, ...ExtraTools::core()]
            : [...StatamicMcpServer::CORE_TOOLS, ...StatamicMcpServer::SEARCHABLE_TOOLS, ...array_keys(ExtraTools::all())];

        $names = [];

        foreach ($classes as $class) {
            try {
                $names[] = app($class)->name();
            } catch (\Throwable) {
                // A tool that cannot be resolved is not in the catalog either.
            }
        }

        return $this->catalogToolNames = $names;
    }

    /**
     * Snapshot of current Statamic system state for context.
     *
     * @return array<string, mixed>
     */
    private function getSystemState(): array
    {
        // Only report what the caller could list through the equivalent router:
        // each section behind its configure permission, each handle filtered by
        // the resource policy. Otherwise discovery hands any authenticated caller
        // the full content-model inventory the routers would refuse them.
        /** @var User|null $user */
        $user = auth()->user();
        $cli = McpContext::isCli();
        /** @var ResourcePolicy $policy */
        $policy = app(ResourcePolicy::class);

        $may = fn (string $permission): bool => $cli || ($user !== null && ($user->isSuper() || $user->hasPermission($permission)));
        /**
         * @param  list<array{handle: string, title: mixed}>  $rows
         *
         * @return list<array{handle: string, title: mixed}>
         */
        $allowed = fn (string $domain, array $rows): array => array_values(array_filter(
            $rows,
            fn (array $row): bool => $policy->canAccess($domain, $row['handle'], 'read'),
        ));

        $state = [];

        try {
            if ($may('configure collections')) {
                /** @var list<array{handle: string, title: mixed}> $rows */
                $rows = Collection::all()->map(function (mixed $c): array {
                    /** @var \Statamic\Contracts\Entries\Collection $c */
                    return ['handle' => (string) $c->handle(), 'title' => $c->title()];
                })->all();
                $state['collections'] = $allowed('structures', $rows);
            }

            if ($may('configure taxonomies')) {
                /** @var list<array{handle: string, title: mixed}> $rows */
                $rows = Taxonomy::all()->map(function (mixed $t): array {
                    /** @var \Statamic\Contracts\Taxonomies\Taxonomy $t */
                    return ['handle' => (string) $t->handle(), 'title' => $t->title()];
                })->all();
                $state['taxonomies'] = $allowed('structures', $rows);
            }

            if ($may('configure asset containers')) {
                /** @var list<array{handle: string, title: mixed}> $rows */
                $rows = AssetContainer::all()->map(function (mixed $c): array {
                    /** @var \Statamic\Contracts\Assets\AssetContainer $c */
                    return ['handle' => (string) $c->handle(), 'title' => $c->title()];
                })->all();
                $state['asset_containers'] = $allowed('assets', $rows);
            }

            if ($may('configure globals')) {
                /** @var list<array{handle: string, title: mixed}> $rows */
                $rows = GlobalSet::all()->map(function (mixed $g): array {
                    /** @var \Statamic\Contracts\Globals\GlobalSet $g */
                    return ['handle' => (string) $g->handle(), 'title' => $g->title()];
                })->all();
                $state['global_sets'] = $allowed('globals', $rows);
            }

            if ($may('configure sites')) {
                $sites = Site::all();
                $siteList = [];
                foreach (is_iterable($sites) ? $sites : [] as $site) {
                    /** @var \Statamic\Sites\Site $site */
                    $siteList[] = ['handle' => $site->handle(), 'name' => $site->name(), 'locale' => $site->locale()];
                }
                $state['sites'] = $siteList;
            }
        } catch (\Exception $e) {
            $state['error'] = 'Partial system state: ' . $e->getMessage();
        }

        return $state;
    }

    /**
     * Dynamic tool summary via reflection on registered routers.
     *
     * @return array<string, array{actions: list<string>, types: list<string>}>
     */
    private function getToolSummary(): array
    {
        $routerClasses = [
            'entries' => 'Cboxdk\\StatamicMcp\\Mcp\\Tools\\Routers\\EntriesRouter',
            'terms' => 'Cboxdk\\StatamicMcp\\Mcp\\Tools\\Routers\\TermsRouter',
            'globals' => 'Cboxdk\\StatamicMcp\\Mcp\\Tools\\Routers\\GlobalsRouter',
            'blueprints' => 'Cboxdk\\StatamicMcp\\Mcp\\Tools\\Routers\\BlueprintsRouter',
            'structures' => 'Cboxdk\\StatamicMcp\\Mcp\\Tools\\Routers\\StructuresRouter',
            'assets' => 'Cboxdk\\StatamicMcp\\Mcp\\Tools\\Routers\\AssetsRouter',
            'users' => 'Cboxdk\\StatamicMcp\\Mcp\\Tools\\Routers\\UsersRouter',
            'system' => 'Cboxdk\\StatamicMcp\\Mcp\\Tools\\Routers\\SystemRouter',
            'content-facade' => 'Cboxdk\\StatamicMcp\\Mcp\\Tools\\Routers\\ContentFacadeRouter',
        ];

        $summary = [];

        foreach ($routerClasses as $domain => $className) {
            try {
                if (! class_exists($className)) {
                    continue;
                }

                /** @var object $instance */
                $instance = app()->make($className);

                /** @var array<string, string> $actionsResult */
                $actionsResult = method_exists($instance, 'getActions') ? $instance->getActions() : [];
                $actions = array_keys($actionsResult);

                /** @var array<string, string> $typesResult */
                $typesResult = method_exists($instance, 'getTypes') ? $instance->getTypes() : [];
                $types = array_keys($typesResult);

                $summary["statamic-{$domain}"] = [
                    'actions' => $actions,
                    'types' => $types,
                ];
            } catch (\Exception) {
                // Skip tools that can't be introspected
            }
        }

        return $summary;
    }
}
