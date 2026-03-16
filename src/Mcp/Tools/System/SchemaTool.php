<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\System;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Lightweight tool schema reference for MCP consumers.
 *
 * Returns a concise tool catalog with domains, actions, and parameter hints.
 * Full schemas are already exposed via the MCP protocol handshake — this tool
 * provides a human/LLM-friendly summary for quick orientation.
 */
#[IsReadOnly]
#[Name('statamic-system-schema')]
#[Description('Quick reference of all Statamic MCP tools, their actions, and key parameters.')]
class SchemaTool extends BaseStatamicTool
{
    protected function defineSchema(JsonSchemaContract $schema): array
    {
        return [
            'tool_name' => JsonSchema::string()
                ->description('Filter to a specific tool (e.g., "statamic-entries")'),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function executeInternal(array $arguments): array
    {
        $toolName = is_string($arguments['tool_name'] ?? null) ? $arguments['tool_name'] : null;

        $catalog = $this->getToolCatalog();

        if ($toolName) {
            if (! isset($catalog[$toolName])) {
                return $this->createErrorResponse("Unknown tool: {$toolName}. Available: " . implode(', ', array_keys($catalog)))->toArray();
            }

            return [
                'tool' => $catalog[$toolName],
            ];
        }

        return [
            'tools' => $catalog,
            'total' => count($catalog),
            'tip' => 'Full JSON schemas are available via the MCP protocol. Use tool_name to inspect a specific tool.',
        ];
    }

    /**
     * Concise tool catalog — one entry per registered tool.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getToolCatalog(): array
    {
        return [
            'statamic-entries' => [
                'domain' => 'content',
                'actions' => ['list', 'get', 'create', 'update', 'delete', 'publish', 'unpublish'],
                'key_params' => ['collection (required)', 'id', 'data', 'site', 'limit', 'offset'],
                'write' => true,
            ],
            'statamic-terms' => [
                'domain' => 'content',
                'actions' => ['list', 'get', 'create', 'update', 'delete'],
                'key_params' => ['taxonomy (required)', 'id', 'slug', 'data', 'site'],
                'write' => true,
            ],
            'statamic-globals' => [
                'domain' => 'content',
                'actions' => ['list', 'get', 'update'],
                'key_params' => ['handle', 'site', 'data'],
                'write' => true,
            ],
            'statamic-blueprints' => [
                'domain' => 'structure',
                'actions' => ['list', 'get', 'create', 'update', 'delete', 'scan', 'generate', 'types', 'validate'],
                'key_params' => ['handle', 'namespace', 'fields', 'include_details', 'include_fields'],
                'write' => true,
            ],
            'statamic-structures' => [
                'domain' => 'structure',
                'actions' => ['list', 'get', 'create', 'update', 'delete', 'configure'],
                'key_params' => ['type (collection|taxonomy|navigation|site|globalset)', 'handle', 'data'],
                'write' => true,
            ],
            'statamic-assets' => [
                'domain' => 'assets',
                'actions' => ['list', 'get', 'create', 'update', 'delete', 'upload', 'move', 'copy'],
                'key_params' => ['container', 'path', 'data'],
                'write' => true,
            ],
            'statamic-users' => [
                'domain' => 'users',
                'actions' => ['list', 'get', 'create', 'update', 'delete', 'activate', 'deactivate', 'assign_role', 'remove_role'],
                'key_params' => ['type (user|role|group)', 'id', 'data', 'role'],
                'write' => true,
            ],
            'statamic-system' => [
                'domain' => 'system',
                'actions' => ['info', 'health', 'cache_status', 'cache_clear', 'cache_warm', 'config_get', 'config_set'],
                'key_params' => ['key', 'value', 'include_details'],
                'write' => true,
            ],
            'statamic-content-facade' => [
                'domain' => 'workflow',
                'actions' => ['execute'],
                'key_params' => ['workflow (setup_collection|bulk_import|content_audit|cross_reference|duplicate_content)', 'collection', 'data'],
                'write' => true,
            ],
            'statamic-system-discover' => [
                'domain' => 'education',
                'actions' => ['discover (intent-based)'],
                'key_params' => ['intent', 'context'],
                'write' => false,
            ],
            'statamic-system-schema' => [
                'domain' => 'education',
                'actions' => ['catalog'],
                'key_params' => ['tool_name'],
                'write' => false,
            ],
        ];
    }
}
