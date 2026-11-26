<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\System;

use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;
use Cboxdk\StatamicMcp\Mcp\ExtraTools;
use Cboxdk\StatamicMcp\Mcp\Servers\StatamicMcpServer;
use Cboxdk\StatamicMcp\Mcp\Support\ResultSchema;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Lightweight tool schema reference for MCP consumers.
 *
 * Returns a concise tool catalog with domains, actions, and parameter hints.
 * Full schemas are already exposed via the MCP protocol handshake — this tool
 * provides a human/LLM-friendly summary for quick orientation.
 */
#[IsReadOnly]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[Name('statamic-system-schema')]
#[Title('Statamic Tool Schemas')]
#[Description('Quick reference of all Statamic MCP tools, their actions, and key parameters.')]
class SchemaTool extends BaseStatamicTool
{
    protected function getToolDomain(): ?string
    {
        return 'guidance';
    }

    protected function successDataSchema(): array
    {
        return ['anyOf' => [
            ResultSchema::object(['tool' => ResultSchema::map()], ['tool']),
            ResultSchema::object(['tools' => ResultSchema::map(), 'total' => ['type' => 'integer', 'minimum' => 0]], ['tools', 'total']),
        ]];
    }

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        return [
            'tool_name' => JsonSchema::string()
                ->description('Filter to a specific tool (e.g., "statamic-entries-write")'),
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

        $catalog = [];
        $server = (new \ReflectionClass(StatamicMcpServer::class))->getDefaultProperties()['tools'];
        foreach (StatamicMcpServer::presented([...(is_array($server) ? $server : []), ...array_keys(ExtraTools::all())]) as $tool) {
            if (! $tool->eligibleForRegistration()) {
                continue;
            }
            $catalog[$tool->name()] = $tool instanceof BaseRouter
                ? $tool->catalogEntry()
                : ['domain' => 'guidance', 'actions' => [], 'resource_types' => [], 'arguments' => array_map('strval', array_keys($tool->schema(new JsonSchemaTypeFactory))), 'provided_by' => 'statamic-mcp'];
        }

        if ($toolName !== null) {
            if (! isset($catalog[$toolName])) {
                return $this->createErrorResponse("Unknown tool: {$toolName}. Available: " . implode(', ', array_keys($catalog)), code: ErrorCode::NotFound, field: 'tool_name', validValues: array_keys($catalog))->toArray();
            }

            return ['tool' => $catalog[$toolName]];
        }

        return [
            'tools' => $catalog,
            'total' => count($catalog),
            'tip' => 'Each tool\'s full JSON schema is in tools/list. Use tool_name to inspect one tool.',
        ];
    }
}
