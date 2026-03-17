<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\System;

use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
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
#[Description('Discover Statamic MCP tools based on intent and get current system state for context-aware guidance.')]
class DiscoveryTool extends BaseStatamicTool
{
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
        if (! app()->runningInConsole()) {
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
            'success' => true,
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
     * @return list<array{tool: string, reason: string}>
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
            'statamic-content-facade' => ['workflow', 'bulk', 'import', 'audit', 'setup collection'],
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
            'statamic-content-facade' => 'High-level workflows: setup_collection, bulk_import, content_audit',
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
     * Snapshot of current Statamic system state for context.
     *
     * @return array<string, mixed>
     */
    private function getSystemState(): array
    {
        $state = [];

        try {
            $state['collections'] = Collection::all()->map(function (mixed $c): array {
                /** @var \Statamic\Contracts\Entries\Collection $c */
                return ['handle' => $c->handle(), 'title' => $c->title()];
            })->values()->all();

            $state['taxonomies'] = Taxonomy::all()->map(function (mixed $t): array {
                /** @var \Statamic\Contracts\Taxonomies\Taxonomy $t */
                return ['handle' => $t->handle(), 'title' => $t->title()];
            })->values()->all();

            $state['asset_containers'] = AssetContainer::all()->map(function (mixed $c): array {
                /** @var \Statamic\Contracts\Assets\AssetContainer $c */
                return ['handle' => $c->handle(), 'title' => $c->title()];
            })->values()->all();

            $state['global_sets'] = GlobalSet::all()->map(function (mixed $g): array {
                /** @var \Statamic\Contracts\Globals\GlobalSet $g */
                return ['handle' => $g->handle(), 'title' => $g->title()];
            })->values()->all();

            $sites = Site::all();
            $sitesIterable = is_iterable($sites) ? $sites : [];
            $siteList = [];
            foreach ($sitesIterable as $site) {
                /** @var \Statamic\Sites\Site $site */
                $siteList[] = [
                    'handle' => $site->handle(),
                    'name' => $site->name(),
                    'locale' => $site->locale(),
                ];
            }
            $state['sites'] = $siteList;
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
