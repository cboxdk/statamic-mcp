<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\Http\UploadedFile;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Contracts\Assets\AssetContainer as AssetContainerContract;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Stache;

#[Name('statamic-assets')]
#[Description('Manage Statamic assets and asset containers. Set resource_type to "container" or "asset", then choose an action. Actions: list, get, create, update, delete, move, copy, upload.')]
class AssetsRouter extends BaseRouter
{
    protected function getDomain(): string
    {
        return 'assets';
    }

    public function getActions(): array
    {
        return [
            'list' => 'List assets or containers with filtering options',
            'get' => 'Get specific asset or container details',
            'create' => 'Create new asset containers or create assets from inline content (base64 or raw text)',
            'update' => 'Update asset metadata or container configuration',
            'delete' => 'Delete assets or containers',
            'move' => 'Move assets to different locations',
            'copy' => 'Copy assets to different locations',
            'upload' => 'Upload a file to a container. Accepts base64-encoded content (for remote clients like ChatGPT) or a local file_path (for CLI clients)',
        ];
    }

    public function getTypes(): array
    {
        return [
            'container' => 'Asset containers that organize and store assets',
            'asset' => 'Individual files stored in asset containers',
        ];
    }

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        return array_merge(parent::defineSchema($schema), [
            'action' => JsonSchema::string()
                ->description(
                    'Action to perform. Required params per action: '
                    . 'list (resource_type; container for assets), '
                    . 'get (resource_type; container + path for assets, handle for containers), '
                    . 'create (resource_type; container + filename + content for assets, handle for containers), '
                    . 'update (resource_type; container + path + data for assets, handle + data for containers), '
                    . 'delete (resource_type; container + path for assets, handle for containers), '
                    . 'move (resource_type=asset, container, path, destination), '
                    . 'copy (resource_type=asset, container, path, destination), '
                    . 'upload (resource_type=asset, container, content+encoding+filename for remote clients OR file_path for local CLI clients)'
                )
                ->enum(['list', 'get', 'create', 'update', 'delete', 'move', 'copy', 'upload'])
                ->required(),
            'resource_type' => JsonSchema::string()
                ->description('Type of asset resource. "container" for storage containers, "asset" for individual files within containers.')
                ->enum(['container', 'asset'])
                ->required(),
            'container' => JsonSchema::string()
                ->description('Asset container handle. Required for asset operations. Example: "images", "documents"'),
            'path' => JsonSchema::string()
                ->description('Asset path within the container including filename. Example: "blog/hero.jpg", "docs/guide.pdf"'),
            'handle' => JsonSchema::string()
                ->description('Deprecated — use "container" for container operations. Container handle'),
            'data' => JsonSchema::object()
                ->description('Asset or container metadata. For assets: field values like alt, title. For containers: configuration like title, disk.'),
            'destination' => JsonSchema::string()
                ->description('Destination path for move/copy operations. Example: "archive/old-hero.jpg"'),
            'filename' => JsonSchema::string()
                ->description('Target filename for create/upload operations. Example: "logo.png", "document.pdf"'),
            'content' => JsonSchema::string()
                ->description('File content as a string. For binary files (images, PDFs): set encoding=base64 and pass the base64-encoded file data. For text files (CSV, JSON, HTML): use encoding=raw (default). Required for create action and for upload action when file_path is not available.'),
            'file_path' => JsonSchema::string()
                ->description('Absolute path to a local file for upload action. Only works for CLI-based MCP clients with filesystem access. Must be within the storage/app directory. Not available for remote/web MCP clients — use content+encoding instead.'),
            'encoding' => JsonSchema::string()
                ->description('How the content parameter is encoded. Use "base64" for binary files like images and PDFs. Use "raw" for plain text files. Default: raw.')
                ->enum(['base64', 'raw']),
            'include_details' => JsonSchema::boolean()
                ->description('Include extended metadata (size, mime type, dimensions for images) in response'),
            'include_counts' => JsonSchema::boolean()
                ->description('Include asset and folder counts. Can be slow with large containers — omit for faster responses'),
            'recursive' => JsonSchema::boolean()
                ->description('Include assets in subdirectories when listing. Default: false (root folder only)'),
            'filters' => JsonSchema::object()
                ->description('Filter conditions for list operations as key-value pairs'),
        ]);
    }

    /**
     * Route actions to appropriate handlers with security checks.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function executeAction(array $arguments): array
    {
        $action = is_string($arguments['action'] ?? null) ? $arguments['action'] : '';

        // Check if tool is enabled for current context
        if (! $this->isCliContext() && ! $this->isWebToolEnabled()) {
            return $this->createErrorResponse('Permission denied: Assets tool is disabled for web access')->toArray();
        }

        // Apply security checks for web context
        if (! $this->isCliContext()) {
            $permissionError = $this->checkWebPermissions($action, $arguments);
            if ($permissionError) {
                return $permissionError;
            }
        }

        $type = is_string($arguments['resource_type'] ?? null) ? $arguments['resource_type'] : '';

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
            $includeCounts = $this->getBooleanArgument($arguments, 'include_counts', false);
            $containers = AssetContainer::all()->map(function ($container) use ($includeDetails, $includeCounts) {
                if (! $container instanceof AssetContainerContract) {
                    return null;
                }

                $data = [
                    'handle' => $container->handle(),
                    'title' => $container->title(),
                    'disk' => $container->diskHandle(),
                ];

                if ($includeDetails) {
                    $permissions = $this->getContainerPermissions($container);
                    $data = array_merge($data, [
                        'blueprint' => $container->blueprint()?->handle(),
                        'url' => $container->url(),
                        'path' => $container->path(),
                        'allow_uploads' => $permissions['allow_uploads'],
                        'allow_downloading' => $permissions['allow_downloading'],
                        'allow_renaming' => $permissions['allow_renaming'],
                        'allow_moving' => $permissions['allow_moving'],
                        'create_folders' => $permissions['create_folders'],
                        'search_index' => $this->getContainerSearchIndex($container),
                    ]);

                    if ($includeCounts) {
                        $data['asset_count'] = $this->getContainerAssetCount($container);
                    }
                }

                return $data;
            })->all();

            return [
                'containers' => $containers,
                'total' => count($containers),
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
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
            $container = AssetContainer::find($handle);

            if (! $container) {
                return $this->createErrorResponse("Asset container not found: {$handle}")->toArray();
            }

            $permissions = $this->getContainerPermissions($container);
            $includeCounts = $this->getBooleanArgument($arguments, 'include_counts', true);
            $data = [
                'handle' => $container->handle(),
                'title' => $container->title(),
                'disk' => $container->diskHandle(),
                'blueprint' => $container->blueprint()?->handle(),
                'url' => $container->url(),
                'path' => $container->path(),
                'allow_uploads' => $permissions['allow_uploads'],
                'allow_downloading' => $permissions['allow_downloading'],
                'allow_renaming' => $permissions['allow_renaming'],
                'allow_moving' => $permissions['allow_moving'],
                'create_folders' => $permissions['create_folders'],
                'search_index' => $this->getContainerSearchIndex($container),
            ];

            if ($includeCounts) {
                $data['asset_count'] = $this->getContainerAssetCount($container);
                $data['folder_count'] = $this->getContainerFolderCount($container);
            }

            return ['container' => $data];
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
            $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
            $handle = is_string($data['handle'] ?? null) ? $data['handle'] : (is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '');

            if (! $handle) {
                return $this->createErrorResponse('Container handle is required')->toArray();
            }

            if (AssetContainer::find($handle)) {
                return $this->createErrorResponse("Container '{$handle}' already exists")->toArray();
            }

            $container = AssetContainer::make($handle);

            // Set configuration
            if (isset($data['title'])) {
                $container->title(is_string($data['title']) ? $data['title'] : '');
            }
            if (isset($data['disk'])) {
                $container->disk(is_string($data['disk']) ? $data['disk'] : '');
            }

            // Set permissions
            $this->setContainerPermissions($container, $data);

            $container->save();

            // Clear caches
            Stache::clear();

            return [
                'container' => [
                    'handle' => $container->handle(),
                    'title' => $container->title(),
                    'created' => true,
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
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
            $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];

            $container = AssetContainer::find($handle);
            if (! $container) {
                return $this->createErrorResponse("Asset container not found: {$handle}")->toArray();
            }

            // Update basic configuration
            if (isset($data['title'])) {
                $container->title(is_string($data['title']) ? $data['title'] : '');
            }
            if (isset($data['disk'])) {
                $container->disk(is_string($data['disk']) ? $data['disk'] : '');
            }

            // Update permissions
            $this->setContainerPermissions($container, $data);

            $container->save();

            // Clear caches
            Stache::clear();

            return [
                'container' => [
                    'handle' => $container->handle(),
                    'title' => $container->title(),
                    'updated' => true,
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
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
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
            Stache::clear();

            return [
                'container' => [
                    'handle' => $handle,
                    'deleted' => true,
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
            $container = is_string($arguments['container'] ?? null) ? $arguments['container'] : null;
            $includeDetails = $this->getBooleanArgument($arguments, 'include_details', true);
            $recursive = $this->getBooleanArgument($arguments, 'recursive', false);

            if (! $container) {
                return $this->createErrorResponse('Container handle is required for listing assets')->toArray();
            }

            $assetContainer = AssetContainer::find($container);
            if (! $assetContainer) {
                return $this->createErrorResponse("Asset container not found: {$container}")->toArray();
            }

            $allAssets = $assetContainer->assets();
            if (! $recursive) {
                $allAssets = $allAssets->where('folder', '');
            }

            $total = $allAssets->count();
            $limit = $this->getIntegerArgument($arguments, 'limit', 50, 1, 500);
            $offset = $this->getIntegerArgument($arguments, 'offset', 0, 0);

            $assets = $allAssets->skip($offset)->take($limit)->map(function ($asset) use ($includeDetails) {
                if (! $asset instanceof AssetContract) {
                    return null;
                }

                $data = [
                    'id' => $asset->id(),
                    'path' => $asset->path(),
                    'basename' => $asset->basename(),
                    'extension' => $asset->extension(),
                    'url' => $asset->url(),
                ];

                if ($includeDetails) {
                    $data['size'] = $asset->size();
                    $data['mime_type'] = $asset->mimeType();
                    $data['is_image'] = $asset->isImage();

                    if ($asset->isImage()) {
                        $data['width'] = $asset->width();
                        $data['height'] = $asset->height();
                        $data['alt'] = $this->getAssetAlt($asset);
                    }
                }

                return $data;
            })->filter()->values()->all();

            return [
                'assets' => $assets,
                'pagination' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $limit) < $total,
                ],
                'container' => $container,
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
            $container = is_string($arguments['container'] ?? null) ? $arguments['container'] : null;
            $path = is_string($arguments['path'] ?? null) ? $arguments['path'] : null;

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
                'alt' => $this->getAssetAlt($asset),
                'title' => $this->getAssetTitle($asset),
                'data' => $asset->data()->all(),
            ];

            return ['asset' => $data];
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

        try {
            $container = is_string($arguments['container'] ?? null) ? $arguments['container'] : null;
            $filename = is_string($arguments['filename'] ?? null) ? $arguments['filename'] : null;
            $content = is_string($arguments['content'] ?? null) ? $arguments['content'] : null;
            $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];

            if (! $container || ! $filename) {
                return $this->createErrorResponse('Container and filename are required')->toArray();
            }

            // Prevent path traversal in filename
            if ($filename !== basename($filename)) {
                return $this->createErrorResponse('Filename must not contain path separators or traversal sequences')->toArray();
            }

            $assetContainer = AssetContainer::find($container);
            if (! $assetContainer) {
                return $this->createErrorResponse("Asset container not found: {$container}")->toArray();
            }

            // Validate file size (default 10MB limit, configurable)
            /** @var int $maxSizeBytes */
            $maxSizeBytes = config('statamic.mcp.security.max_upload_size', 10 * 1024 * 1024);

            // Handle content creation
            if ($content) {
                $encoding = is_string($arguments['encoding'] ?? null) ? $arguments['encoding'] : 'raw';

                if ($encoding === 'base64') {
                    $decodedContent = base64_decode($content, true);
                    if ($decodedContent === false) {
                        return $this->createErrorResponse('Invalid base64 content provided')->toArray();
                    }
                } else {
                    $decodedContent = $content;
                }

                if (strlen($decodedContent) > $maxSizeBytes) {
                    $maxSizeMB = round($maxSizeBytes / 1024 / 1024, 1);

                    return $this->createErrorResponse("File size exceeds maximum allowed size of {$maxSizeMB}MB")->toArray();
                }

                // Create temporary file with guaranteed cleanup using random name
                $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'statamic_asset_' . bin2hex(random_bytes(16));
                if (file_put_contents($tempPath, '') === false) {
                    return $this->createErrorResponse('Failed to create temporary file for upload')->toArray();
                }

                try {
                    file_put_contents($tempPath, $decodedContent);

                    $mimeType = 'application/octet-stream';
                    if (function_exists('finfo_open')) {
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        if ($finfo !== false) {
                            $detected = finfo_file($finfo, $tempPath);
                            if ($detected !== false) {
                                $mimeType = $detected;
                            }
                            finfo_close($finfo);
                        }
                    } elseif (function_exists('mime_content_type')) {
                        $mimeType = mime_content_type($tempPath) ?: 'application/octet-stream';
                    }

                    // Upload the file
                    $asset = $assetContainer->makeAsset($filename)
                        ->upload(new UploadedFile(
                            $tempPath,
                            $filename,
                            $mimeType,
                            null,
                            true
                        ));
                } finally {
                    if (file_exists($tempPath)) {
                        if (! unlink($tempPath)) {
                            Log::warning('Failed to clean up MCP temp file', [
                                'path' => $tempPath,
                            ]);
                        }
                    }
                }
            } else {
                return $this->createErrorResponse('Content is required to create an asset. Provide file content with encoding parameter (base64 or raw).')->toArray();
            }

            // Set additional data
            foreach ($data as $key => $value) {
                $asset->set($key, $value);
            }

            $asset->save();

            // Clear caches
            Stache::clear();

            return [
                'asset' => [
                    'id' => $asset->id(),
                    'path' => $asset->path(),
                    'filename' => $asset->filename(),
                    'basename' => $asset->basename(),
                    'extension' => $asset->extension(),
                    'size' => $asset->size(),
                    'mime_type' => $asset->mimeType(),
                    'url' => $asset->url(),
                    'data' => $asset->data()->all(),
                ],
                'created' => true,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to create asset: {$e->getMessage()}")->toArray();
        }
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
            $container = is_string($arguments['container'] ?? null) ? $arguments['container'] : null;
            $path = is_string($arguments['path'] ?? null) ? $arguments['path'] : null;
            $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];

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
            Stache::clear();

            return [
                'asset' => [
                    'id' => $asset->id(),
                    'path' => $asset->path(),
                    'updated' => true,
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
            $container = is_string($arguments['container'] ?? null) ? $arguments['container'] : null;
            $path = is_string($arguments['path'] ?? null) ? $arguments['path'] : null;

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
            Stache::clear();

            return [
                'asset' => [
                    'id' => $assetId,
                    'deleted' => true,
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
            $container = is_string($arguments['container'] ?? null) ? $arguments['container'] : null;
            $path = is_string($arguments['path'] ?? null) ? $arguments['path'] : null;
            $destination = is_string($arguments['destination'] ?? null) ? $arguments['destination'] : null;

            if (! $container || ! $path || ! $destination) {
                return $this->createErrorResponse('Container, path, and destination are required')->toArray();
            }

            $containerObj = AssetContainer::find($container);
            if (! $containerObj) {
                return $this->createErrorResponse("Asset container not found: {$container}")->toArray();
            }

            // Check container permissions
            if (! $this->containerAllows($containerObj, 'move')) {
                return $this->createErrorResponse("Container '{$container}' does not allow moving assets")->toArray();
            }

            $asset = Asset::find($container . '::' . $path);
            if (! $asset) {
                return $this->createErrorResponse("Asset not found: {$container}::{$path}")->toArray();
            }

            $oldPath = $asset->path();
            $asset->move($destination);
            $asset->save();

            // Clear caches
            Stache::clear();

            return [
                'asset' => [
                    'id' => $asset->id(),
                    'old_path' => $oldPath,
                    'new_path' => $asset->path(),
                    'moved' => true,
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
            $container = is_string($arguments['container'] ?? null) ? $arguments['container'] : null;
            $path = is_string($arguments['path'] ?? null) ? $arguments['path'] : null;
            $destination = is_string($arguments['destination'] ?? null) ? $arguments['destination'] : null;

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
            Stache::clear();

            return [
                'original' => [
                    'id' => $asset->id(),
                    'path' => $asset->path(),
                ],
                'copy' => [
                    'id' => $newAsset->id(),
                    'path' => $newAsset->path(),
                    'copied' => true,
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

        try {
            $container = is_string($arguments['container'] ?? null) ? $arguments['container'] : null;
            $file_path = is_string($arguments['file_path'] ?? null) ? $arguments['file_path'] : null;
            $filename = is_string($arguments['filename'] ?? null) ? $arguments['filename'] : null;
            $content = is_string($arguments['content'] ?? null) ? $arguments['content'] : null;
            $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];

            if (! $container) {
                return $this->createErrorResponse('Container is required')->toArray();
            }

            if (! $file_path && ! $content) {
                return $this->createErrorResponse('Either file_path (local files) or content with encoding+filename (remote/base64) is required')->toArray();
            }

            $assetContainer = AssetContainer::find($container);
            if (! $assetContainer) {
                return $this->createErrorResponse("Asset container not found: {$container}")->toArray();
            }

            if ($content) {
                // Remote upload via base64/raw content (for MCP clients like ChatGPT that can't access the filesystem)
                if (! $filename) {
                    return $this->createErrorResponse('filename is required when uploading via content')->toArray();
                }

                if ($filename !== basename($filename)) {
                    return $this->createErrorResponse('Filename must not contain path separators or traversal sequences')->toArray();
                }

                $encoding = is_string($arguments['encoding'] ?? null) ? $arguments['encoding'] : 'raw';

                if ($encoding === 'base64') {
                    $decodedContent = base64_decode($content, true);
                    if ($decodedContent === false) {
                        return $this->createErrorResponse('Invalid base64 content provided')->toArray();
                    }
                } else {
                    $decodedContent = $content;
                }

                /** @var int $maxSizeBytes */
                $maxSizeBytes = config('statamic.mcp.security.max_upload_size', 10 * 1024 * 1024);
                if (strlen($decodedContent) > $maxSizeBytes) {
                    $maxSizeMB = round($maxSizeBytes / 1024 / 1024, 1);

                    return $this->createErrorResponse("File size exceeds maximum allowed size of {$maxSizeMB}MB")->toArray();
                }

                $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'statamic_upload_' . bin2hex(random_bytes(16));

                try {
                    file_put_contents($tempPath, $decodedContent);

                    $mimeType = 'application/octet-stream';
                    if (function_exists('finfo_open')) {
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        if ($finfo !== false) {
                            $detected = finfo_file($finfo, $tempPath);
                            if ($detected !== false) {
                                $mimeType = $detected;
                            }
                            finfo_close($finfo);
                        }
                    } elseif (function_exists('mime_content_type')) {
                        $mimeType = mime_content_type($tempPath) ?: 'application/octet-stream';
                    }

                    $asset = $assetContainer->makeAsset($filename)->upload(new UploadedFile(
                        $tempPath,
                        $filename,
                        $mimeType,
                        null,
                        true
                    ));
                } finally {
                    if (file_exists($tempPath)) {
                        unlink($tempPath);
                    }
                }
            } else {
                // Local file upload (CLI MCP clients with filesystem access)
                // $file_path is guaranteed non-null here (early return above checks both)
                $realPath = realpath($file_path);
                $allowedBase = realpath(storage_path('app'));
                if ($realPath === false || ! $allowedBase || ! str_starts_with($realPath, $allowedBase . DIRECTORY_SEPARATOR)) {
                    return $this->createErrorResponse('file_path must be within the storage/app directory for security')->toArray();
                }

                if (! file_exists($file_path)) {
                    return $this->createErrorResponse("File not found: {$file_path}")->toArray();
                }

                $filename = $filename ?? basename($file_path);

                if ($filename !== basename($filename)) {
                    return $this->createErrorResponse('Filename must not contain path separators or traversal sequences')->toArray();
                }

                $mimeType = mime_content_type($file_path) ?: 'application/octet-stream';

                $asset = $assetContainer->makeAsset($filename)->upload(new UploadedFile(
                    $file_path,
                    $filename,
                    $mimeType,
                    null,
                    true
                ));
            }

            // Set additional data
            foreach ($data as $key => $value) {
                $asset->set($key, $value);
            }

            $asset->save();

            // Clear caches
            Stache::clear();

            return [
                'asset' => [
                    'id' => $asset->id(),
                    'path' => $asset->path(),
                    'filename' => $asset->filename(),
                    'basename' => $asset->basename(),
                    'extension' => $asset->extension(),
                    'size' => $asset->size(),
                    'mime_type' => $asset->mimeType(),
                    'url' => $asset->url(),
                    'data' => $asset->data()->all(),
                ],
                'uploaded' => true,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to upload asset: {$e->getMessage()}")->toArray();
        }
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
        $type = $arguments['resource_type'] ?? '';
        $container = is_string($arguments['container'] ?? null) ? $arguments['container'] : '';

        if ($type === 'container') {
            return match ($action) {
                'list', 'get' => $container !== '' ? ["view {$container} assets"] : ['configure asset containers'],
                'create', 'update', 'delete' => ['configure asset containers'],
                default => ['super'],
            };
        }

        if ($type === 'asset') {
            return match ($action) {
                'list', 'get' => $container !== '' ? ["view {$container} assets"] : ['configure asset containers'],
                'upload', 'create' => $container !== '' ? ["upload {$container} assets"] : ['configure asset containers'],
                'update' => $container !== '' ? ["edit {$container} assets"] : ['configure asset containers'],
                'move', 'copy' => $container !== '' ? ["move {$container} assets"] : ['configure asset containers'],
                'rename' => $container !== '' ? ["rename {$container} assets"] : ['configure asset containers'],
                'delete' => $container !== '' ? ["delete {$container} assets"] : ['configure asset containers'],
                default => ['super'],
            };
        }

        return ['super'];
    }

    /**
     * Get container permission settings.
     * In Statamic 6, permissions are user-based, not container-based.
     *
     * @return array<string, bool|null>
     */
    private function getContainerPermissions(AssetContainerContract $container): array
    {
        return [
            'allow_uploads' => true,
            'allow_downloading' => true,
            'allow_renaming' => true,
            'allow_moving' => true,
            'create_folders' => true,
        ];
    }

    /**
     * Set container permissions (no-op in Statamic 6).
     *
     * @param  array<string, mixed>  $data
     */
    private function setContainerPermissions(AssetContainerContract $container, array $data): void
    {
        // Permissions are user-based in Statamic 6, not container-based
    }

    /**
     * Check if container allows a specific operation.
     */
    private function containerAllows(AssetContainerContract $container, string $operation): bool
    {
        // All operations are allowed by default; permissions are user-based
        return true;
    }

    /**
     * Get container search index.
     */
    private function getContainerSearchIndex(AssetContainerContract $container): ?string
    {
        try {
            return $container->searchIndex();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get container asset count.
     */
    private function getContainerAssetCount(AssetContainerContract $container): int
    {
        try {
            return $container->assets()->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Get container folder count.
     */
    private function getContainerFolderCount(AssetContainerContract $container): int
    {
        try {
            return $container->folders()->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Get asset alt text.
     */
    private function getAssetAlt(AssetContract $asset): ?string
    {
        try {
            // Try to get alt text from asset data
            return $asset->get('alt');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get asset title.
     */
    private function getAssetTitle(AssetContract $asset): ?string
    {
        try {
            // The title() method exists on the contract
            return $asset->title();
        } catch (\Throwable) {
            return null;
        }
    }
}
