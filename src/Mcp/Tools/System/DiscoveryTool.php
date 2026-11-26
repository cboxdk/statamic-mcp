<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\System;

use Cboxdk\StatamicMcp\Actions\Transport\RegisteredReadTool;
use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;
use Cboxdk\StatamicMcp\Mcp\ExtraTools;
use Cboxdk\StatamicMcp\Mcp\Servers\StatamicMcpServer;
use Cboxdk\StatamicMcp\Mcp\Support\ResultSchema;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\RouterAction;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\AssetsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\GlobalsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Cboxdk\StatamicMcp\Support\McpContext;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[Name('statamic-discover')]
#[Title('Statamic Tool Discovery')]
#[Description('Lists available capabilities, matches an optional intent to tools, or returns one tool’s input and output schemas with tool_name. Availability reflects token scopes and installation profile; each call still checks its resource and Statamic permissions.')]
class DiscoveryTool extends BaseStatamicTool
{
    private const KEYWORDS = [
        'entries' => ['entry', 'entries', 'content', 'article', 'page', 'post', 'publish', 'draft'],
        'terms' => ['term', 'terms', 'taxonomy', 'tag', 'category'],
        'globals' => ['global', 'globals', 'site settings', 'footer', 'header', 'seo'],
        'blueprints' => ['blueprint', 'field', 'schema', 'fieldset', 'fieldtype', 'scan'],
        'structures' => ['collection', 'navigation', 'nav', 'structure', 'taxonomy setup', 'site config'],
        'assets' => ['asset', 'file', 'image', 'media', 'upload', 'container', 'storage'],
        'users' => ['user', 'role', 'permission', 'auth', 'group', 'access'],
        'system' => ['cache', 'health', 'system', 'info', 'performance', 'maintenance', 'config'],
        'content-facade' => ['workflow', 'audit', 'validate content', 'schema drift', 'broken', 'orphan'],
    ];

    protected function getToolDomain(): ?string
    {
        return 'guidance';
    }

    protected function successDataSchema(): array
    {
        return ['anyOf' => [
            ResultSchema::object(['discovery' => ResultSchema::object([
                'recommended_tools' => ['type' => 'array'], 'available_tools' => ResultSchema::map(),
            ], ['recommended_tools', 'available_tools'])], ['discovery']),
            ResultSchema::object(['tool' => ResultSchema::map()], ['tool']),
        ]];
    }

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        return [
            'intent' => JsonSchema::string()->description('Optional task to match, for example "edit an entry". Omit for the available catalog.'),
            'tool_name' => JsonSchema::string()->description('Return the schema of this available tool instead of intent matches, for example "statamic-entries-write".'),
        ];
    }

    protected function executeInternal(array $arguments): array
    {
        $tools = $this->availableTools();
        if (isset($arguments['tool_name'])) {
            $name = is_string($arguments['tool_name']) ? $arguments['tool_name'] : '';
            if (! isset($tools[$name])) {
                return $this->createErrorResponse('Tool is unknown or unavailable to this connection.', code: ErrorCode::NotFound, field: 'tool_name', validValues: array_keys($tools))->toArray();
            }
            $tool = $tools[$name];
            $wire = $tool->toArray();
            if ($tool instanceof BaseRouter) {
                $actions = $tool->discoverableActions();
                Arr::set($wire, 'inputSchema.properties.action.enum', array_map(fn (RouterAction $a): string => $a->name, $actions));
                Arr::set($wire, 'inputSchema.properties.action.description', implode(' ', array_map(fn (RouterAction $a): string => $a->summary(), $actions)));
            }

            return ['tool' => [
                ...$this->summary($tool), 'name' => $name,
                'inputSchema' => $wire['inputSchema'] ?? [], 'outputSchema' => $wire['outputSchema'] ?? null,
                'annotations' => $wire['annotations'] ?? [],
            ]];
        }

        $intent = strtolower(is_string($arguments['intent'] ?? null) ? $arguments['intent'] : '');
        $summary = [];
        foreach ($tools as $name => $tool) {
            $summary[$name] = $this->summary($tool);
        }
        $discovery = ['recommended_tools' => $this->recommend($tools, $intent), 'available_tools' => $summary];
        $token = request()->attributes->get('mcp_token');
        if (McpContext::isCli() || ($token instanceof McpTokenData && app(TokenService::class)->hasScope($token, TokenScope::SystemRead))) {
            $discovery['system_state'] = $this->systemState();
        }

        return ['discovery' => $discovery];
    }

    /** @return array<string, Tool> */
    private function availableTools(): array
    {
        $tools = [];
        foreach ([...StatamicMcpServer::CORE_TOOLS, ...StatamicMcpServer::SEARCHABLE_TOOLS, ...array_keys(ExtraTools::all())] as $class) {
            // An extension failure must not hide the rest of the catalog.
            try {
                foreach (StatamicMcpServer::presented([$class]) as $tool) {
                    if ($tool->eligibleForRegistration() && (! $tool instanceof BaseRouter || ($tool->availableToCaller() && $tool->discoverableActions() !== []))) {
                        $tools[$tool->name()] = $tool;
                    }
                }
            } catch (\Throwable $error) {
                report($error);
            }
        }

        foreach (StatamicMcpServer::registeredReadTools() as $tool) {
            if ($tool->eligibleForRegistration()) {
                $tools[$tool->name()] = $tool;
            }
        }

        return $tools;
    }

    /** @return array<string, mixed> */
    private function summary(Tool $tool): array
    {
        if ($tool instanceof RegisteredReadTool) {
            return $tool->catalogEntry();
        }
        if (! $tool instanceof BaseRouter) {
            return ['domain' => 'guidance', 'actions' => [], 'resource_types' => [], 'provided_by' => 'statamic-mcp'];
        }

        return [...$tool->catalogEntry(), 'actions' => array_map(fn (RouterAction $a): string => $a->name, $tool->discoverableActions())];
    }

    /**
     * @param  array<string, Tool>  $tools
     *
     * @return list<array<string, string>>
     */
    private function recommend(array $tools, string $intent): array
    {
        $matches = [];
        foreach ($tools as $tool) {
            if (! $tool instanceof BaseRouter) {
                continue;
            }
            try {
                $extra = ExtraTools::isRegistered($tool::class);
                $keywords = $extra ? $tool->discoveryKeywords() : (self::KEYWORDS[$tool->catalogEntry()['domain']] ?? []);
                $keywords = array_unique(array_filter(array_map(fn (string $word): string => strtolower(trim($word)), $keywords)));
                $score = count(array_filter($keywords, fn (string $word): bool => str_contains($intent, $word)));
                if ($score === 0) {
                    continue;
                }
                if (isset($matches[$tool::class])) {
                    $matches[$tool::class]['write_tool'] = $tool->name();
                    continue;
                }
                $matches[$tool::class] = [
                    'tool' => $tool->name(),
                    'reason' => $extra ? $tool->discoveryReason() : $tool->title(),
                    'access' => $this->accessPath($tool), '_score' => $score,
                ];
            } catch (\Throwable $error) {
                report($error);
            }
        }
        uasort($matches, fn (array $a, array $b): int => $b['_score'] <=> $a['_score']);

        return array_values(array_map(function (array $match): array {
            return array_filter($match, is_string(...));
        }, $matches));
    }

    private function accessPath(Tool $tool): string
    {
        return ! config('statamic.mcp.catalog.searchable', false)
            || in_array($tool::class, [...StatamicMcpServer::CORE_TOOLS, ...ExtraTools::core()], true)
            ? 'listed_in_catalog' : 'via_execute_tools';
    }

    /** @return array<string, mixed> */
    private function systemState(): array
    {
        $state = [];
        foreach ([
            'collections' => [new StructuresRouter, 'collection'],
            'taxonomies' => [new StructuresRouter, 'taxonomy'],
            'sites' => [new StructuresRouter, 'site'],
            'asset_containers' => [new AssetsRouter, 'container'],
            'global_sets' => [new GlobalsRouter, null],
        ] as $key => [$router, $type]) {
            $arguments = ['action' => 'list', 'limit' => 50];
            if ($type !== null) {
                $arguments['resource_type'] = $type;
            }
            $result = $router->facet('read')->execute($arguments);
            if (($result['success'] ?? false) === true && is_array($result['data'] ?? null)) {
                // Keep the pagination contract: an inventory can exceed one page.
                $state[$key] = $result['data'];
            }
        }

        return $state;
    }
}
