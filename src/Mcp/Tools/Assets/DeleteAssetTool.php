<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Assets;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\AssetContainer;

#[Title('Delete Asset Container')]
class DeleteAssetTool extends BaseStatamicTool
{
    use ClearsCaches;
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.assets.delete';
    }

    protected function getToolDescription(): string
    {
        return 'Delete an asset container with safety checks';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('handle')
            ->description('Container handle')
            ->required()
            ->boolean('force')
            ->description('Force deletion even if container contains assets')
            ->optional();
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
        $handle = $arguments['handle'];
        $force = $arguments['force'] ?? false;

        $container = AssetContainer::find($handle);
        if (! $container) {
            return $this->createErrorResponse("Container '{$handle}' not found")->toArray();
        }

        try {
            $assetCount = $container->assets()->count();

            if ($assetCount > 0 && ! $force) {
                return $this->createErrorResponse(
                    "Cannot delete container '{$handle}' because it contains {$assetCount} assets. Use 'force: true' to delete anyway.",
                    [
                        'asset_count' => $assetCount,
                        'force_required' => true,
                    ]
                )->toArray();
            }

            $container->delete();

            // Clear caches
            $cacheTypes = $this->getRecommendedCacheTypes('structure_change');
            $cacheResult = $this->clearStatamicCaches($cacheTypes);

            return [
                'handle' => $handle,
                'asset_count' => $assetCount,
                'forced' => $force,
                'message' => "Asset container '{$handle}' deleted successfully",
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not delete container: ' . $e->getMessage())->toArray();
        }
    }
}
