<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Assets;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;

#[Title('Rename Statamic Asset')]
class RenameAssetTool extends BaseStatamicTool
{
    use ClearsCaches;

    protected function getToolName(): string
    {
        return 'statamic.assets.rename';
    }

    protected function getToolDescription(): string
    {
        return 'Rename an asset file while preserving its metadata and references';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $this->addDryRunSchema($schema)
            ->string('container')
            ->description('Asset container handle')
            ->required()
            ->string('path')
            ->description('Current asset path within container')
            ->required()
            ->string('new_filename')
            ->description('New filename (with extension)')
            ->required()
            ->boolean('create_backup')
            ->description('Create backup before renaming (default: true)')
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
        $newFilename = $arguments['new_filename'];
        $dryRun = $arguments['dry_run'] ?? false;
        $createBackup = $arguments['create_backup'] ?? true;

        // Validate container and asset
        $container = AssetContainer::find($containerHandle);
        if (! $container) {
            return $this->createErrorResponse("Asset container '{$containerHandle}' not found")->toArray();
        }

        $asset = Asset::find("{$containerHandle}::{$path}");
        if (! $asset) {
            return $this->createErrorResponse("Asset '{$path}' not found in container '{$containerHandle}'")->toArray();
        }

        // Calculate new path
        $pathInfo = pathinfo($path);
        $dirname = $pathInfo['dirname'] ?? '.';
        $directory = $dirname === '.' ? '' : $dirname . '/';
        $newPath = $directory . $newFilename;

        // Validate new filename
        if (empty($newFilename) || str_contains($newFilename, '/')) {
            return $this->createErrorResponse("Invalid filename '{$newFilename}'. Filename should not contain directory separators.")->toArray();
        }

        // Check if target path already exists
        $targetAsset = Asset::find("{$containerHandle}::{$newPath}");
        if ($targetAsset) {
            return $this->createErrorResponse("Asset already exists at '{$newPath}' in container '{$containerHandle}'")->toArray();
        }

        if ($dryRun) {
            return [
                'dry_run' => true,
                'would_rename' => [
                    'container' => $containerHandle,
                    'from' => [
                        'path' => $path,
                        'filename' => $pathInfo['basename'],
                        'directory' => $dirname,
                    ],
                    'to' => [
                        'path' => $newPath,
                        'filename' => $newFilename,
                        'directory' => $dirname,
                    ],
                    'backup_created' => $createBackup,
                ],
            ];
        }

        // Create backup if requested
        $backupInfo = null;
        if ($createBackup) {
            try {
                $backupPath = $path . '.backup.' . now()->format('Y-m-d-His');
                $container->disk()->copy($path, $backupPath);
                $backupInfo = [
                    'path' => $backupPath,
                    'created_at' => now()->toISOString(),
                ];
            } catch (\Exception $e) {
                return $this->createErrorResponse("Failed to create backup: {$e->getMessage()}")->toArray();
            }
        }

        try {
            // Rename the file on disk
            $container->disk()->move($path, $newPath);

            // Update asset path in Statamic
            $asset->path($newPath);
            $asset->save();

            // Clear caches
            $cacheResult = $this->clearStatamicCaches(['stache', 'static', 'images']);

            return [
                'success' => true,
                'renamed' => [
                    'container' => $containerHandle,
                    'from' => [
                        'path' => $path,
                        'filename' => $pathInfo['basename'],
                    ],
                    'to' => [
                        'path' => $newPath,
                        'filename' => $newFilename,
                    ],
                    'asset_id' => $asset->id(),
                ],
                'backup' => $backupInfo,
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            // Restore backup if rename failed and backup was created
            if ($backupInfo) {
                try {
                    $container->disk()->copy($backupInfo['path'], $path);
                } catch (\Exception $restoreException) {
                    // Log restore failure but don't mask original error
                }
            }

            return $this->createErrorResponse("Failed to rename asset: {$e->getMessage()}")->toArray();
        }
    }
}
