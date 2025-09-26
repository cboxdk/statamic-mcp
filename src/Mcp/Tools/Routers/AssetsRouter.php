<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ExecutesWithAudit;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\RouterHelpers;
use Illuminate\JsonSchema\JsonSchema;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;

class AssetsRouter extends BaseRouter
{
    use ExecutesWithAudit;
    use RouterHelpers;

    protected function getToolName(): string
    {
        return 'statamic.assets';
    }

    protected function getToolDescription(): string
    {
        return 'Manage Statamic assets and asset containers: list, get, create, update, delete, move, copy operations';
    }

    protected function getDomain(): string
    {
        return 'assets';
    }

    protected function getActions(): array
    {
        return [
            'list' => [
                'description' => 'List assets or containers with filtering options',
                'purpose' => 'Asset discovery and browsing',
                'destructive' => false,
                'examples' => [
                    ['action' => 'list', 'type' => 'container'],
                    ['action' => 'list', 'type' => 'asset', 'container' => 'main'],
                ],
            ],
            'get' => [
                'description' => 'Get specific asset or container details',
                'purpose' => 'Asset inspection and metadata retrieval',
                'destructive' => false,
                'examples' => [
                    ['action' => 'get', 'type' => 'asset', 'container' => 'main', 'path' => 'image.jpg'],
                ],
            ],
            'create' => [
                'description' => 'Create new asset containers or upload assets',
                'purpose' => 'Asset and container creation',
                'destructive' => false,
                'examples' => [
                    ['action' => 'create', 'type' => 'container', 'handle' => 'photos'],
                ],
            ],
            'update' => [
                'description' => 'Update asset metadata or container configuration',
                'purpose' => 'Asset and container modification',
                'destructive' => true,
                'examples' => [
                    ['action' => 'update', 'type' => 'asset', 'container' => 'main', 'path' => 'image.jpg'],
                ],
            ],
            'delete' => [
                'description' => 'Delete assets or containers',
                'purpose' => 'Asset and container removal',
                'destructive' => true,
                'examples' => [
                    ['action' => 'delete', 'type' => 'asset', 'container' => 'main', 'path' => 'old-image.jpg'],
                ],
            ],
            'move' => [
                'description' => 'Move assets to different locations',
                'purpose' => 'Asset reorganization',
                'destructive' => true,
                'examples' => [
                    ['action' => 'move', 'type' => 'asset', 'container' => 'main', 'path' => 'image.jpg', 'destination' => 'folder/image.jpg'],
                ],
            ],
            'copy' => [
                'description' => 'Copy assets to different locations',
                'purpose' => 'Asset duplication',
                'destructive' => false,
                'examples' => [
                    ['action' => 'copy', 'type' => 'asset', 'container' => 'main', 'path' => 'image.jpg', 'destination' => 'backup/image.jpg'],
                ],
            ],
            'upload' => [
                'description' => 'Upload new assets to containers',
                'purpose' => 'Asset creation via file upload',
                'destructive' => false,
                'examples' => [
                    ['action' => 'upload', 'type' => 'asset', 'container' => 'main'],
                ],
            ],
        ];
    }

    protected function getTypes(): array
    {
        return [
            'container' => [
                'description' => 'Asset containers that organize and store assets',
                'properties' => ['handle', 'title', 'disk', 'path', 'url'],
                'relationships' => ['assets'],
                'examples' => ['main', 'images', 'documents'],
            ],
            'asset' => [
                'description' => 'Individual files stored in asset containers',
                'properties' => ['path', 'filename', 'extension', 'size', 'mime_type', 'last_modified'],
                'relationships' => ['container'],
                'examples' => ['image.jpg', 'document.pdf', 'video.mp4'],
            ],
        ];
    }

    protected function defineSchema(JsonSchema $schema): array
    {
        return array_merge(parent::defineSchema($schema), [
            'type' => JsonSchema::string()
                ->description('Asset type to operate on')
                ->enum(['container', 'asset'])
                ->required(),
            'container' => JsonSchema::string()
                ->description('Asset container handle'),
            'path' => JsonSchema::string()
                ->description('Asset path within container'),
            'handle' => JsonSchema::string()
                ->description('Container handle (required for container operations)'),
            'data' => JsonSchema::object()
                ->description('Asset or container data for create/update operations'),
            'destination' => JsonSchema::string()
                ->description('Destination path for move/copy operations'),
            'include_details' => JsonSchema::boolean()
                ->description('Include detailed information (default: true)'),
            'recursive' => JsonSchema::boolean()
                ->description('Include subdirectories recursively (default: false)'),
            'filters' => JsonSchema::object()
                ->description('Filtering options for list operations'),
        ]);
    }

    /**
     * Route actions to appropriate handlers with security checks and audit logging.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function executeAction(array $arguments): array
    {
        $action = $arguments['action'];

        // Check if tool is enabled for current context
        if (! $this->isCliContext() && ! $this->isWebToolEnabled()) {
            return $this->createErrorResponse('Permission denied: Assets tool is disabled for web access')->toArray();
        }

        // Apply security checks for web context
        if ($this->isWebContext()) {
            $permissionError = $this->checkWebPermissions($action, $arguments);
            if ($permissionError) {
                return $permissionError;
            }
        }

        // Execute action with audit logging
        return $this->executeWithAuditLog($action, $arguments);
    }

    /**
     * Perform the actual domain action.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function performDomainAction(string $action, array $arguments): array
    {
        $type = $arguments['type'];

        // Route to type-specific handlers
        return match ($type) {
            'container' => $this->handleContainerAction($action, $arguments),
            'asset' => $this->handleAssetAction($action, $arguments),
            default => $this->createErrorResponse("Unknown asset type: {$type}")->toArray(),
        };
    }

    /**
     * Handle asset container operations.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function handleContainerAction(string $action, array $arguments): array
    {
        return match ($action) {
            'list' => $this->listContainers($arguments),
            'get' => $this->getContainer($arguments),
            'create' => $this->createContainer($arguments),
            'update' => $this->updateContainer($arguments),
            'delete' => $this->deleteContainer($arguments),
            default => $this->createErrorResponse("Unknown container action: {$action}")->toArray(),
        };
    }

    /**
     * Handle asset operations.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function handleAssetAction(string $action, array $arguments): array
    {
        return match ($action) {
            'list' => $this->listAssets($arguments),
            'get' => $this->getAsset($arguments),
            'create' => $this->createAsset($arguments),
            'update' => $this->updateAsset($arguments),
            'delete' => $this->deleteAsset($arguments),
            'move' => $this->moveAsset($arguments),
            'copy' => $this->copyAsset($arguments),
            'upload' => $this->uploadAsset($arguments),
            default => $this->createErrorResponse("Unknown asset action: {$action}")->toArray(),
        };
    }

    // Container Operations

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function listContainers(array $arguments): array
    {
        try {
            $includeDetails = $this->getBooleanArgument($arguments, 'include_details', true);
            $containers = AssetContainer::all()->map(function ($container) use ($includeDetails) {
                $data = [
                    'handle' => $container->handle(),
                    'title' => $container->title(),
                    'disk' => $container->diskHandle(),
                ];

                if ($includeDetails) {
                    $data = array_merge($data, [
                        'blueprint' => $container->blueprint()?->handle(),
                        'url' => $container->url(),
                        'path' => $container->path(),
                        'allow_uploads' => $container->allowUploads(),
                        'allow_downloading' => $container->allowDownloading(),
                        'allow_renaming' => $container->allowRenaming(),
                        'allow_moving' => $container->allowMoving(),
                        'create_folders' => $container->createFolders(),
                        'search_index' => $container->searchIndex(),
                        'asset_count' => $container->assets()->count(),
                    ]);
                }

                return $data;
            })->all();

            return [
                'success' => true,
                'data' => [
                    'containers' => $containers,
                    'total' => count($containers),
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to list containers: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getContainer(array $arguments): array
    {
        try {
            $handle = $arguments['handle'];
            $container = AssetContainer::find($handle);

            if (! $container) {
                return $this->createErrorResponse("Asset container not found: {$handle}")->toArray();
            }

            $data = [
                'handle' => $container->handle(),
                'title' => $container->title(),
                'disk' => $container->diskHandle(),
                'blueprint' => $container->blueprint()?->handle(),
                'url' => $container->url(),
                'path' => $container->path(),
                'allow_uploads' => $container->allowUploads(),
                'allow_downloading' => $container->allowDownloading(),
                'allow_renaming' => $container->allowRenaming(),
                'allow_moving' => $container->allowMoving(),
                'create_folders' => $container->createFolders(),
                'search_index' => $container->searchIndex(),
                'asset_count' => $container->assets()->count(),
                'folder_count' => $container->folders()->count(),
            ];

            return [
                'success' => true,
                'data' => ['container' => $data],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to get container: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function createContainer(array $arguments): array
    {
        if (! $this->hasPermission('create', 'asset_containers')) {
            return $this->createErrorResponse('Permission denied: Cannot create asset containers')->toArray();
        }

        try {
            $data = $arguments['data'] ?? [];
            $handle = $data['handle'] ?? null;

            if (! $handle) {
                return $this->createErrorResponse('Container handle is required')->toArray();
            }

            if (AssetContainer::find($handle)) {
                return $this->createErrorResponse("Container '{$handle}' already exists")->toArray();
            }

            $container = AssetContainer::make($handle);

            // Set configuration
            if (isset($data['title'])) {
                $container->title($data['title']);
            }
            if (isset($data['disk'])) {
                $container->disk($data['disk']);
            }
            if (isset($data['allow_uploads'])) {
                $container->allowUploads($data['allow_uploads']);
            }
            if (isset($data['allow_downloading'])) {
                $container->allowDownloading($data['allow_downloading']);
            }
            if (isset($data['allow_renaming'])) {
                $container->allowRenaming($data['allow_renaming']);
            }
            if (isset($data['allow_moving'])) {
                $container->allowMoving($data['allow_moving']);
            }
            if (isset($data['create_folders'])) {
                $container->createFolders($data['create_folders']);
            }

            $container->save();

            // Clear caches
            $this->clearCaches(['stache']);

            return [
                'success' => true,
                'data' => [
                    'container' => [
                        'handle' => $container->handle(),
                        'title' => $container->title(),
                        'created' => true,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to create container: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateContainer(array $arguments): array
    {
        if (! $this->hasPermission('edit', 'asset_containers')) {
            return $this->createErrorResponse('Permission denied: Cannot update asset containers')->toArray();
        }

        try {
            $handle = $arguments['handle'];
            $data = $arguments['data'] ?? [];

            $container = AssetContainer::find($handle);
            if (! $container) {
                return $this->createErrorResponse("Asset container not found: {$handle}")->toArray();
            }

            // Update configuration
            foreach ($data as $key => $value) {
                match ($key) {
                    'title' => $container->title($value),
                    'disk' => $container->disk($value),
                    'allow_uploads' => $container->allowUploads($value),
                    'allow_downloading' => $container->allowDownloading($value),
                    'allow_renaming' => $container->allowRenaming($value),
                    'allow_moving' => $container->allowMoving($value),
                    'create_folders' => $container->createFolders($value),
                    default => null, // Ignore unknown fields
                };
            }

            $container->save();

            // Clear caches
            $this->clearCaches(['stache']);

            return [
                'success' => true,
                'data' => [
                    'container' => [
                        'handle' => $container->handle(),
                        'title' => $container->title(),
                        'updated' => true,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to update container: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deleteContainer(array $arguments): array
    {
        if (! $this->hasPermission('delete', 'asset_containers')) {
            return $this->createErrorResponse('Permission denied: Cannot delete asset containers')->toArray();
        }

        try {
            $handle = $arguments['handle'];
            $container = AssetContainer::find($handle);

            if (! $container) {
                return $this->createErrorResponse("Asset container not found: {$handle}")->toArray();
            }

            // Check for existing assets
            $assetCount = $container->assets()->count();
            if ($assetCount > 0) {
                return $this->createErrorResponse("Cannot delete container '{$handle}' - it contains {$assetCount} assets")->toArray();
            }

            $container->delete();

            // Clear caches
            $this->clearCaches(['stache']);

            return [
                'success' => true,
                'data' => [
                    'container' => [
                        'handle' => $handle,
                        'deleted' => true,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to delete container: {$e->getMessage()}")->toArray();
        }
    }

    // Asset Operations

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function listAssets(array $arguments): array
    {
        try {
            $container = $arguments['container'] ?? null;
            $includeDetails = $this->getBooleanArgument($arguments, 'include_details', true);
            $recursive = $this->getBooleanArgument($arguments, 'recursive', false);

            if (! $container) {
                return $this->createErrorResponse('Container handle is required for listing assets')->toArray();
            }

            $assetContainer = AssetContainer::find($container);
            if (! $assetContainer) {
                return $this->createErrorResponse("Asset container not found: {$container}")->toArray();
            }

            $query = $assetContainer->assets();
            if (! $recursive) {
                $query = $query->where('folder', '');
            }

            $assets = $query->map(function ($asset) use ($includeDetails) {
                $data = [
                    'id' => $asset->id(),
                    'path' => $asset->path(),
                    'basename' => $asset->basename(),
                    'filename' => $asset->filename(),
                    'extension' => $asset->extension(),
                    'url' => $asset->url(),
                ];

                if ($includeDetails) {
                    $data = array_merge($data, [
                        'container' => $asset->containerHandle(),
                        'folder' => $asset->folder(),
                        'size' => $asset->size(),
                        'last_modified' => $asset->lastModified()->timestamp ?? null,
                        'mime_type' => $asset->mimeType(),
                        'is_image' => $asset->isImage(),
                        'is_video' => $asset->isVideo(),
                        'is_audio' => $asset->isAudio(),
                        'width' => $asset->width(),
                        'height' => $asset->height(),
                        'alt' => $asset->alt(),
                        'title' => $asset->title(),
                    ]);
                }

                return $data;
            })->all();

            return [
                'success' => true,
                'data' => [
                    'assets' => $assets,
                    'total' => count($assets),
                    'container' => $container,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to list assets: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getAsset(array $arguments): array
    {
        try {
            $container = $arguments['container'] ?? null;
            $path = $arguments['path'] ?? null;

            if (! $container || ! $path) {
                return $this->createErrorResponse('Both container and path are required')->toArray();
            }

            $asset = Asset::find($container . '::' . $path);
            if (! $asset) {
                return $this->createErrorResponse("Asset not found: {$container}::{$path}")->toArray();
            }

            $data = [
                'id' => $asset->id(),
                'path' => $asset->path(),
                'basename' => $asset->basename(),
                'filename' => $asset->filename(),
                'extension' => $asset->extension(),
                'url' => $asset->url(),
                'container' => $asset->containerHandle(),
                'folder' => $asset->folder(),
                'size' => $asset->size(),
                'last_modified' => $asset->lastModified()->timestamp ?? null,
                'mime_type' => $asset->mimeType(),
                'is_image' => $asset->isImage(),
                'is_video' => $asset->isVideo(),
                'is_audio' => $asset->isAudio(),
                'width' => $asset->width(),
                'height' => $asset->height(),
                'alt' => $asset->alt(),
                'title' => $asset->title(),
                'data' => $asset->data()->all(),
            ];

            return [
                'success' => true,
                'data' => ['asset' => $data],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to get asset: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function createAsset(array $arguments): array
    {
        if (! $this->hasPermission('create', 'assets')) {
            return $this->createErrorResponse('Permission denied: Cannot create assets')->toArray();
        }

        // Asset creation via file upload - placeholder implementation
        return $this->createErrorResponse('Asset creation via API not yet implemented - use upload action')->toArray();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateAsset(array $arguments): array
    {
        if (! $this->hasPermission('edit', 'assets')) {
            return $this->createErrorResponse('Permission denied: Cannot update assets')->toArray();
        }

        try {
            $container = $arguments['container'] ?? null;
            $path = $arguments['path'] ?? null;
            $data = $arguments['data'] ?? [];

            if (! $container || ! $path) {
                return $this->createErrorResponse('Both container and path are required')->toArray();
            }

            $asset = Asset::find($container . '::' . $path);
            if (! $asset) {
                return $this->createErrorResponse("Asset not found: {$container}::{$path}")->toArray();
            }

            // Update asset data
            foreach ($data as $key => $value) {
                match ($key) {
                    'alt' => $asset->set('alt', $value),
                    'title' => $asset->set('title', $value),
                    default => $asset->set($key, $value),
                };
            }

            $asset->save();

            // Clear caches
            $this->clearCaches(['stache', 'static']);

            return [
                'success' => true,
                'data' => [
                    'asset' => [
                        'id' => $asset->id(),
                        'path' => $asset->path(),
                        'updated' => true,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to update asset: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deleteAsset(array $arguments): array
    {
        if (! $this->hasPermission('delete', 'assets')) {
            return $this->createErrorResponse('Permission denied: Cannot delete assets')->toArray();
        }

        try {
            $container = $arguments['container'] ?? null;
            $path = $arguments['path'] ?? null;

            if (! $container || ! $path) {
                return $this->createErrorResponse('Both container and path are required')->toArray();
            }

            $asset = Asset::find($container . '::' . $path);
            if (! $asset) {
                return $this->createErrorResponse("Asset not found: {$container}::{$path}")->toArray();
            }

            $assetId = $asset->id();
            $asset->delete();

            // Clear caches
            $this->clearCaches(['stache', 'static']);

            return [
                'success' => true,
                'data' => [
                    'asset' => [
                        'id' => $assetId,
                        'deleted' => true,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to delete asset: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function moveAsset(array $arguments): array
    {
        if (! $this->hasPermission('edit', 'assets')) {
            return $this->createErrorResponse('Permission denied: Cannot move assets')->toArray();
        }

        try {
            $container = $arguments['container'] ?? null;
            $path = $arguments['path'] ?? null;
            $destination = $arguments['destination'] ?? null;

            if (! $container || ! $path || ! $destination) {
                return $this->createErrorResponse('Container, path, and destination are required')->toArray();
            }

            $asset = Asset::find($container . '::' . $path);
            if (! $asset) {
                return $this->createErrorResponse("Asset not found: {$container}::{$path}")->toArray();
            }

            $oldPath = $asset->path();
            $asset->move($destination);
            $asset->save();

            // Clear caches
            $this->clearCaches(['stache', 'static']);

            return [
                'success' => true,
                'data' => [
                    'asset' => [
                        'id' => $asset->id(),
                        'old_path' => $oldPath,
                        'new_path' => $asset->path(),
                        'moved' => true,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to move asset: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function copyAsset(array $arguments): array
    {
        if (! $this->hasPermission('create', 'assets')) {
            return $this->createErrorResponse('Permission denied: Cannot copy assets')->toArray();
        }

        try {
            $container = $arguments['container'] ?? null;
            $path = $arguments['path'] ?? null;
            $destination = $arguments['destination'] ?? null;

            if (! $container || ! $path || ! $destination) {
                return $this->createErrorResponse('Container, path, and destination are required')->toArray();
            }

            $asset = Asset::find($container . '::' . $path);
            if (! $asset) {
                return $this->createErrorResponse("Asset not found: {$container}::{$path}")->toArray();
            }

            $newAsset = $asset->copy($destination);
            $newAsset->save();

            // Clear caches
            $this->clearCaches(['stache', 'static']);

            return [
                'success' => true,
                'data' => [
                    'original' => [
                        'id' => $asset->id(),
                        'path' => $asset->path(),
                    ],
                    'copy' => [
                        'id' => $newAsset->id(),
                        'path' => $newAsset->path(),
                        'copied' => true,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to copy asset: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function uploadAsset(array $arguments): array
    {
        if (! $this->hasPermission('create', 'assets')) {
            return $this->createErrorResponse('Permission denied: Cannot upload assets')->toArray();
        }

        // Asset upload via API - placeholder implementation
        return $this->createErrorResponse('Asset upload via API not yet implemented')->toArray();
    }

    // Helper Methods

    // BaseRouter Abstract Method Implementations

    /**
     * @return array<string, mixed>
     */
    protected function getFeatures(): array
    {
        return [
            'file_management' => 'Complete file upload, organization, and manipulation capabilities',
            'container_management' => 'Asset container creation and configuration',
            'batch_operations' => 'Bulk asset operations for efficiency',
            'metadata_handling' => 'Asset metadata and property management',
            'path_operations' => 'Move, copy, and organize assets within containers',
        ];
    }

    protected function getPrimaryUse(): string
    {
        return 'Manage digital assets and their containers for Statamic websites';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getDecisionTree(): array
    {
        return [
            'asset_vs_container' => 'Choose type=asset for individual files, type=container for organization',
            'operation_flow' => 'List → Get details → Create/Update/Delete with dry_run first',
            'safety_checks' => 'Always verify container and path before destructive operations',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getContextAwareness(): array
    {
        return [
            'permission_context' => 'Respects Statamic asset permissions and user capabilities',
            'container_context' => 'Operations scoped to specific asset containers',
            'file_system_context' => 'Aware of disk storage configuration and limits',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getWorkflowIntegration(): array
    {
        return [
            'content_workflow' => 'Assets integrate with entry and page content',
            'media_management' => 'Organize assets for efficient content creation',
            'deployment_workflow' => 'Asset optimization and CDN integration patterns',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getCommonPatterns(): array
    {
        return [
            'asset_discovery' => [
                'description' => 'Explore available assets and containers',
                'pattern' => 'list containers → list assets in container → get specific asset details',
                'example' => ['action' => 'list', 'type' => 'container'],
            ],
            'asset_upload' => [
                'description' => 'Upload and organize new assets',
                'pattern' => 'create container → upload assets → update metadata',
                'example' => ['action' => 'upload', 'type' => 'asset', 'container' => 'photos'],
            ],
            'asset_organization' => [
                'description' => 'Reorganize and maintain asset structure',
                'pattern' => 'move assets → update references → verify integrity',
                'example' => ['action' => 'move', 'type' => 'asset', 'dry_run' => true],
            ],
        ];
    }

    /**
     * Get required permissions for action.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string>
     */
    protected function getRequiredPermissions(string $action, array $arguments): array
    {
        $type = $arguments['type'] ?? '';

        if ($type === 'container') {
            return match ($action) {
                'list', 'get' => ['view assets'],
                'create', 'update' => ['configure asset containers'],
                'delete' => ['configure asset containers'],
                default => ['super'],
            };
        }

        if ($type === 'asset') {
            return match ($action) {
                'list', 'get' => ['view assets'],
                'upload', 'create' => ['upload assets'],
                'update', 'move', 'copy', 'rename' => ['edit assets'],
                'delete' => ['delete assets'],
                default => ['super'],
            };
        }

        return ['super'];
    }
}
