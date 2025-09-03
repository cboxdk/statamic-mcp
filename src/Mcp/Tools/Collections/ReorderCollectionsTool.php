<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Collections;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;

#[Title('Reorder Statamic Collections')]
class ReorderCollectionsTool extends BaseStatamicTool
{
    use ClearsCaches;
    use HasCommonSchemas;

    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.collections.reorder';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Reorder Statamic collections via configuration updates';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->raw('order', [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Array of collection handles in desired order',
            ])
            ->required();
    }

    /**
     * Execute the tool.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        /** @var array<string> $order */
        $order = $arguments['order'];

        try {
            // Placeholder - Statamic has no built-in collection ordering

            $cacheResult = $this->clearStatamicCaches($this->getRecommendedCacheTypes('collection_change'));

            return [
                'order' => $order,
                'cache_cleared' => $cacheResult['cache_cleared'] ?? false,
                'message' => 'Collections reorder request processed (placeholder implementation)',
                'note' => 'Collection reordering requires custom implementation or CP integration',
            ];
        } catch (\Exception $e) {
            return [
                'error' => 'Failed to reorder collections: ' . $e->getMessage(),
                'order' => $order,
            ];
        }
    }
}
