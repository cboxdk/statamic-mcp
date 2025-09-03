<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\System;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;

#[Title('Clear Statamic Caches')]
class ClearCacheTool extends BaseStatamicTool
{
    use ClearsCaches;

    protected function getToolName(): string
    {
        return 'statamic.system.cache.clear';
    }

    protected function getToolDescription(): string
    {
        return 'Clear specific Statamic caches (stache, static, images, glide, views)';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->raw('types', [
                'type' => 'array',
                'items' => [
                    'type' => 'string',
                    'enum' => ['stache', 'static', 'images', 'glide', 'views', 'application'],
                ],
                'description' => 'Cache types to clear',
            ])
            ->required()
            ->boolean('force')
            ->description('Force clear even if cache is locked (default: false)')
            ->optional();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $types = $arguments['types'];
        $force = $arguments['force'] ?? false;

        // Validate cache types
        $validTypes = ['stache', 'static', 'images', 'glide', 'views', 'application'];
        $invalidTypes = array_diff($types, $validTypes);

        if (! empty($invalidTypes)) {
            return $this->createErrorResponse('Invalid cache types: ' . implode(', ', $invalidTypes) . '. Valid types: ' . implode(', ', $validTypes))->toArray();
        }

        // Clear the specified caches
        $result = $this->clearStatamicCaches($types);

        return [
            'action' => 'clear',
            'types_requested' => $types,
            'results' => $result,
            'force' => $force,
            'timestamp' => now()->toISOString(),
        ];
    }
}
