<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Assets;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;

#[Title('Copy Statamic Asset')]
class CopyAssetTool extends BaseStatamicTool
{
    use ClearsCaches;

    protected function getToolName(): string
    {
        return 'statamic.assets.copy';
    }

    protected function getToolDescription(): string
    {
        return 'Copy an asset to a new path within the same container or to a different container';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $this->addDryRunSchema($schema)
            ->string('container')
            ->description('Source asset container handle')
            ->required()
            ->string('path')
            ->description('Source asset path within container')
            ->required()
            ->string('new_path')
            ->description('Target asset path')
            ->required()
            ->string('new_container')
            ->description('Target container handle (optional, defaults to source container)')
            ->optional()
            ->boolean('overwrite')
            ->description('Overwrite target if it exists (default: false)')
            ->optional();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $containerHandle = $arguments['container'];
        $path = $arguments['path'];
        $newPath = $arguments['new_path'];
        $newContainerHandle = $arguments['new_container'] ?? $containerHandle;
        $dryRun = $arguments['dry_run'] ?? false;
        $overwrite = $arguments['overwrite'] ?? false;

        // Validate source container and asset
        $sourceContainer = AssetContainer::find($containerHandle);
        if (! $sourceContainer) {
            return $this->createErrorResponse("Asset container '{$containerHandle}' not found")->toArray();
        }

        $asset = Asset::find("{$containerHandle}::{$path}");
        if (! $asset) {
            return $this->createErrorResponse("Asset '{$path}' not found in container '{$containerHandle}'")->toArray();
        }

        // Validate target container if different
        $targetContainer = $sourceContainer;
        if ($newContainerHandle !== $containerHandle) {
            $targetContainer = AssetContainer::find($newContainerHandle);
            if (! $targetContainer) {
                return $this->createErrorResponse("Target container '{$newContainerHandle}' not found")->toArray();
            }
        }

        // Check if target path already exists
        $targetAsset = Asset::find("{$newContainerHandle}::{$newPath}");
        if ($targetAsset && ! $overwrite) {
            return $this->createErrorResponse("Asset already exists at '{$newPath}' in container '{$newContainerHandle}'. Use overwrite=true to replace.")->toArray();
        }

        if ($dryRun) {
            return [
                'dry_run' => true,
                'would_copy' => [
                    'from' => [
                        'container' => $containerHandle,
                        'path' => $path,
                        'size' => $asset->size(),
                        'mime_type' => $asset->mimeType(),
                    ],
                    'to' => [
                        'container' => $newContainerHandle,
                        'path' => $newPath,
                    ],
                    'cross_container' => $containerHandle !== $newContainerHandle,
                    'will_overwrite' => $targetAsset !== null ? $overwrite : false,
                ],
            ];
        }

        try {
            // Perform the copy operation
            if ($containerHandle === $newContainerHandle) {
                // Same container copy
                $sourceContainer->disk()->copy($path, $newPath);
            } else {
                // Cross-container copy
                $targetContainer->disk()->writeStream(
                    $newPath,
                    $sourceContainer->disk()->readStream($path)
                );
            }

            // Create new asset reference in Statamic
            $newAsset = $asset->toArray();
            $newAsset['path'] = $newPath;

            if ($containerHandle !== $newContainerHandle) {
                $copiedAsset = Asset::make()
                    ->container($newContainerHandle)
                    ->path($newPath)
                    ->data($asset->data()->all());
            } else {
                $copiedAsset = Asset::make()
                    ->container($containerHandle)
                    ->path($newPath)
                    ->data($asset->data()->all());
            }

            $copiedAsset->save();

            // Clear caches
            $cacheResult = $this->clearStatamicCaches(['stache', 'static', 'images']);

            return [
                'success' => true,
                'copied' => [
                    'from' => [
                        'container' => $containerHandle,
                        'path' => $path,
                        'id' => $asset->id(),
                    ],
                    'to' => [
                        'container' => $newContainerHandle,
                        'path' => $newPath,
                        'id' => $copiedAsset->id(),
                    ],
                    'cross_container' => $containerHandle !== $newContainerHandle,
                    'overwritten' => $targetAsset !== null,
                ],
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to copy asset: {$e->getMessage()}")->toArray();
        }
    }
}
