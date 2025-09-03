<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Assets;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;

#[Title('Move Statamic Asset')]
class MoveAssetTool extends BaseStatamicTool
{
    use ClearsCaches;

    protected function getToolName(): string
    {
        return 'statamic.assets.move';
    }

    protected function getToolDescription(): string
    {
        return 'Move an asset to a new path within the same container or to a different container';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $this->addDryRunSchema($schema)
            ->string('container')
            ->description('Source asset container handle')
            ->required()
            ->string('path')
            ->description('Current asset path within container')
            ->required()
            ->string('new_path')
            ->description('New asset path')
            ->required()
            ->string('new_container')
            ->description('Target container handle (optional, defaults to source container)')
            ->optional()
            ->boolean('create_backup')
            ->description('Create backup before moving (default: true)')
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
        $createBackup = $arguments['create_backup'] ?? true;

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
        if ($targetAsset) {
            return $this->createErrorResponse("Asset already exists at '{$newPath}' in container '{$newContainerHandle}'")->toArray();
        }

        if ($dryRun) {
            return [
                'dry_run' => true,
                'would_move' => [
                    'from' => [
                        'container' => $containerHandle,
                        'path' => $path,
                        'full_path' => $sourceContainer->diskPath() . '/' . $path,
                    ],
                    'to' => [
                        'container' => $newContainerHandle,
                        'path' => $newPath,
                        'full_path' => $targetContainer->diskPath() . '/' . $newPath,
                    ],
                    'cross_container' => $containerHandle !== $newContainerHandle,
                    'backup_created' => $createBackup,
                ],
            ];
        }

        // Create backup if requested
        $backupInfo = null;
        if ($createBackup) {
            try {
                $backupPath = $path . '.backup.' . now()->format('Y-m-d-His');
                $sourceContainer->disk()->copy($path, $backupPath);
                $backupInfo = [
                    'path' => $backupPath,
                    'container' => $containerHandle,
                    'created_at' => now()->toISOString(),
                ];
            } catch (\Exception $e) {
                return $this->createErrorResponse("Failed to create backup: {$e->getMessage()}")->toArray();
            }
        }

        try {
            // If moving within same container, use rename
            if ($containerHandle === $newContainerHandle) {
                $sourceContainer->disk()->move($path, $newPath);
            } else {
                // Cross-container move: copy then delete
                $targetContainer->disk()->writeStream(
                    $newPath,
                    $sourceContainer->disk()->readStream($path)
                );
                $sourceContainer->disk()->delete($path);
            }

            // Update asset reference in Statamic
            $asset->path($newPath);
            if ($containerHandle !== $newContainerHandle) {
                $asset->container($newContainerHandle);
            }
            $asset->save();

            // Clear caches
            $cacheResult = $this->clearStatamicCaches(['stache', 'static', 'images']);

            return [
                'success' => true,
                'moved' => [
                    'from' => [
                        'container' => $containerHandle,
                        'path' => $path,
                    ],
                    'to' => [
                        'container' => $newContainerHandle,
                        'path' => $newPath,
                    ],
                    'cross_container' => $containerHandle !== $newContainerHandle,
                ],
                'backup' => $backupInfo,
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            // Restore backup if move failed and backup was created
            if ($backupInfo) {
                try {
                    $sourceContainer->disk()->copy($backupInfo['path'], $path);
                } catch (\Exception $restoreException) {
                    // Log restore failure but don't mask original error
                }
            }

            return $this->createErrorResponse("Failed to move asset: {$e->getMessage()}")->toArray();
        }
    }
}
