<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;
use Cboxdk\StatamicMcp\Mcp\Exceptions\ToolFailure;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ChecksResourceVersions;
use Cboxdk\StatamicMcp\Mcp\Tools\RouterAction;
use Cboxdk\StatamicMcp\Support\Http\FetchRefused;
use Cboxdk\StatamicMcp\Support\Http\OutboundFetcher;
use Cboxdk\StatamicMcp\Support\McpConfig;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\Http\UploadedFile;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Statamic\Assets\AssetUploader;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Contracts\Assets\AssetContainer as AssetContainerContract;
use Statamic\Contracts\Auth\User;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Rules\AllowedFile;
use Statamic\Support\Str;

#[Name('statamic-assets')]
#[Title('Statamic Assets')]
#[Description('Statamic assets and asset containers: files, their metadata, and where they are stored.')]
class AssetsRouter extends BaseRouter
{
    use ChecksResourceVersions;

    protected function getDomain(): string
    {
        return 'assets';
    }

    /** An upload can fetch a URL the client names. */
    protected function writesReachOutside(): bool
    {
        return true;
    }

    protected function actions(): array
    {
        return [
            RouterAction::read('list', 'List containers, or the assets in one container', optional: ['container', 'folder', 'recursive', 'include_details', 'include_counts', 'limit', 'cursor']),
            RouterAction::read('get', 'Get a container, or an asset by its path', needs: ['container'], optional: ['path', 'include_counts', 'fields']),
            RouterAction::edit('create', 'Create a container (its settings in data), or an asset from inline content (filename, content, encoding)', needs: ['container'], optional: ['data', 'filename', 'content', 'encoding']),
            RouterAction::edit('update', 'Update a container\'s settings, or an asset\'s metadata by its path', needs: ['container', 'data'], optional: ['path']),
            RouterAction::delete('delete', 'Delete a container, or an asset by its path', needs: ['container'], optional: ['path']),
            RouterAction::edit('move', 'Move or rename an asset', ['asset'], needs: ['container', 'path', 'destination']),
            RouterAction::edit('copy', 'Copy an asset', ['asset'], needs: ['container', 'path', 'destination']),
            RouterAction::edit('upload', 'Upload a file into a container: source_url (the server fetches it), content with encoding and filename, or file_path (local stdio only)', ['asset'], needs: ['container', 'source_url|content|file_path'], optional: ['filename', 'encoding', 'folder', 'data']),
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
            'container' => JsonSchema::string()
                ->description('Asset container handle: the container to act on, or for container create the new container\'s handle. Example: "images", "documents"'),
            'path' => JsonSchema::string()
                ->description('Asset path within the container including filename. Example: "blog/hero.jpg", "docs/guide.pdf"'),
            'data' => JsonSchema::object()
                ->description('Asset or container metadata. For assets: field values like alt, title. For containers: configuration like title, disk.'),
            'destination' => JsonSchema::string()
                ->description('New path for move/copy, filename included: "archive/old-hero.jpg". End it with "/" to keep the filename: "archive/". The extension cannot change.'),
            'filename' => JsonSchema::string()
                ->description('Target filename for create/upload operations. Example: "logo.png", "document.pdf". The extension must be one Statamic accepts for uploads.'),
            'folder' => JsonSchema::string()
                ->description('Folder inside the container. For upload: the folder to upload into. For list: only list assets in this folder (set recursive to include its subfolders). Example: "uploads/cases". Default: the container root.'),
            'content' => JsonSchema::string()
                ->description('File content as a string. For binary files (images, PDFs): set encoding=base64 and pass the base64-encoded file data. For text files (CSV, JSON, HTML): use encoding=raw (default). Required for create action and for upload action when file_path is not available.'),
            'source_url' => JsonSchema::string()
                ->description('For upload: an http(s) URL the server downloads the file from — the way for a remote client to add an image without sending it as base64. Public hosts only. The filename defaults to the last segment of the URL.'),
            'file_path' => JsonSchema::string()
                ->description('Absolute path to a local file for upload action. Only works for CLI-based MCP clients with filesystem access. Must be within the storage/app directory. Not available for remote/web MCP clients — use content+encoding instead.'),
            'encoding' => JsonSchema::string()
                ->description('How the content parameter is encoded. Use "base64" for binary files like images and PDFs. Use "raw" for plain text files. Default: raw.')
                ->enum(['base64', 'raw']),
            'include_details' => JsonSchema::boolean()
                ->description('Include extended metadata (size, mime type, dimensions for images) in response'),
            'include_counts' => JsonSchema::boolean()
                ->description('Include asset and folder counts. Can be slow with large containers — omit for faster responses'),
            'limit' => JsonSchema::integer()
                ->description('Maximum assets to return (default: 50, max: 500)'),
            'cursor' => JsonSchema::string()
                ->description('next_cursor from the previous page. Keep the other arguments unchanged. Omit to start again.'),
            'recursive' => JsonSchema::boolean()
                ->description('Include assets in subdirectories of the listed folder. Default: false (only the folder itself, the container root when no folder is given)'),
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
        $includeDetails = $this->getBooleanArgument($arguments, 'include_details', true);
        $includeCounts = $this->getBooleanArgument($arguments, 'include_counts', false);
        // An unscoped list has no handle for the outer permission and
        // resource-policy checks to key on, so each container is checked here.
        $containers = AssetContainer::all()->filter(
            fn ($container): bool => $container instanceof AssetContainerContract && $this->canListContainer($container->handle())
        )->map(function (AssetContainerContract $container) use ($includeDetails, $includeCounts) {
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
        })->values()->all();

        return $this->paginateItems($containers, $arguments);
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getContainer(array $arguments): array
    {
        $handle = is_string($arguments['container'] ?? null) ? $arguments['container'] : '';
        $container = AssetContainer::find($handle);
        if (! $container) {
            return $this->missingResource('Asset container', $handle, 'container', AssetContainer::all()->map->handle()->all(), $arguments)->toArray();
        }
        $includeCounts = $this->getBooleanArgument($arguments, 'include_counts', true);
        $data = $this->containerRecord($container);
        if ($includeCounts) {
            $data['asset_count'] = $this->getContainerAssetCount($container);
            $data['folder_count'] = $this->getContainerFolderCount($container);
        }

        return ['container' => $data];
    }

    /** @return array<string, mixed> */
    private function containerRecord(AssetContainerContract $container): array
    {
        $permissions = $this->getContainerPermissions($container);
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

        return [...$data, 'version' => self::resourceVersion($container->fileData())];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function createContainer(array $arguments): array
    {
        $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
        $handle = is_string($arguments['container'] ?? null) ? $arguments['container'] : '';
        if (! $handle) {
            return $this->createErrorResponse('Container handle is required')->toArray();
        }
        if (array_key_exists('handle', $data) && $data['handle'] !== $handle) {
            return $this->createErrorResponse('The new container\'s handle is the container argument; data.handle may only repeat it.')->toArray();
        }
        $existsError = $this->checkHandleNotExists(AssetContainer::find($handle), 'Container', $handle);
        if ($existsError !== null) {
            return $existsError;
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
        $this->persistStatamic(fn () => $container->save());
        // Clear caches
        $this->clearCachesAfterWrite(['stache']);

        return [
            'container' => [
                ...$this->containerRecord($container),
                'created' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateContainer(array $arguments): array
    {
        $handle = is_string($arguments['container'] ?? null) ? $arguments['container'] : '';
        $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
        $container = AssetContainer::find($handle);
        if (! $container) {
            return $this->missingResource('Asset container', $handle, 'container', AssetContainer::all()->map->handle()->all(), $arguments)->toArray();
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
        $this->persistStatamic(fn () => $container->save());
        // Clear caches
        $this->clearCachesAfterWrite(['stache']);

        return [
            'container' => [
                ...$this->containerRecord($container),
                'updated' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deleteContainer(array $arguments): array
    {
        $handle = is_string($arguments['container'] ?? null) ? $arguments['container'] : '';
        $container = AssetContainer::find($handle);
        if (! $container) {
            return $this->missingResource('Asset container', $handle, 'container', AssetContainer::all()->map->handle()->all(), $arguments)->toArray();
        }
        // Check for existing assets
        $assetCount = $container->assets()->count();
        if ($assetCount > 0) {
            return $this->createErrorResponse("Cannot delete container '{$handle}' - it contains {$assetCount} assets")->toArray();
        }
        $snapshot = $this->containerRecord($container);
        $this->persistStatamic(fn () => $container->delete());
        // Clear caches
        $this->clearCachesAfterWrite(['stache']);

        return [
            'container' => [
                ...$snapshot,
                'deleted' => true,
            ],
        ];
    }

    // Asset Operations

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function listAssets(array $arguments): array
    {
        $container = is_string($arguments['container'] ?? null) ? $arguments['container'] : null;
        $includeDetails = $this->getBooleanArgument($arguments, 'include_details', true);
        $recursive = $this->getBooleanArgument($arguments, 'recursive', false);
        if (! $container) {
            return $this->createErrorResponse('Container handle is required for listing assets')->toArray();
        }
        $assetContainer = AssetContainer::find($container);
        if (! $assetContainer) {
            return $this->missingResource('Asset container', $container, 'container', AssetContainer::all()->map->handle()->all(), $arguments)->toArray();
        }
        $folder = $this->assetFolder($arguments['folder'] ?? null);
        if ($folder === null) {
            return $this->createErrorResponse("folder must be a path inside the container, such as 'uploads/cases', without '.' or '..' segments")->toArray();
        }
        // Statamic reports root assets with folder '/', not ''. Its own
        // assets() resolves the root and subfolders, recursive or not.
        $allAssets = collect($assetContainer->assets($folder === '' ? '/' : $folder, $recursive)->all())
            ->filter(fn (mixed $asset): bool => $asset instanceof AssetContract);
        $total = $allAssets->count();
        ['limit' => $limit, 'offset' => $offset] = $this->getPaginationArgs($arguments);
        $assets = $allAssets->sortBy(fn (AssetContract $asset): string => $asset->id())->skip($offset)->take($limit)->map(function (AssetContract $asset) use ($includeDetails): array {
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
            ...$this->buildPage($assets, $total, $offset, $arguments),
            'container' => $container,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getAsset(array $arguments): array
    {
        $container = is_string($arguments['container'] ?? null) ? $arguments['container'] : null;
        $path = is_string($arguments['path'] ?? null) ? $arguments['path'] : null;
        if (! $container || ! $path) {
            return $this->createErrorResponse('Both container and path are required')->toArray();
        }
        $asset = Asset::find($container . '::' . $path);
        if (! $asset) {
            return $this->missingResource('Asset', "{$container}::{$path}", 'path')->toArray();
        }

        return ['asset' => $this->assetRecord($asset)];
    }

    /** @return array<string, mixed> */
    private function assetRecord(AssetContract $asset): array
    {
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

        // URLs can be signed and expire; they are not part of the stored record.
        return [...$data, 'version' => self::resourceVersion([
            'id' => $data['id'], 'data' => $data['data'],
            'size' => $data['size'], 'last_modified' => $data['last_modified'],
        ])];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function createAsset(array $arguments): array
    {
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
            return $this->missingResource('Asset container', $container, 'container', AssetContainer::all()->map->handle()->all(), $arguments)->toArray();
        }
        // Validate file size (default 10MB limit, configurable)
        $maxSizeBytes = McpConfig::int('security.max_upload_size', 10 * 1024 * 1024);
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

            $stored = $this->storeContent($assetContainer, '', $filename, $decodedContent);
            if (is_string($stored)) {
                return $this->createErrorResponse($stored)->toArray();
            }
            $asset = $stored;
        } else {
            return $this->createErrorResponse('Content is required to create an asset. Provide file content with encoding parameter (base64 or raw).')->toArray();
        }
        // Set additional data
        foreach ($data as $key => $value) {
            $asset->set($key, $value);
        }
        $this->persistStatamic(fn () => $asset->save());
        // Clear caches
        $this->clearCachesAfterWrite(['stache']);

        return [
            'asset' => $this->assetRecord($asset),
            'created' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateAsset(array $arguments): array
    {
        $container = is_string($arguments['container'] ?? null) ? $arguments['container'] : null;
        $path = is_string($arguments['path'] ?? null) ? $arguments['path'] : null;
        $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
        if (! $container || ! $path) {
            return $this->createErrorResponse('Both container and path are required')->toArray();
        }
        $asset = Asset::find($container . '::' . $path);
        if (! $asset) {
            return $this->missingResource('Asset', "{$container}::{$path}", 'path')->toArray();
        }
        // Keep a rejected candidate out of the repository-held instance.
        $asset = clone $asset;
        // Update asset data
        foreach ($data as $key => $value) {
            match ($key) {
                'alt' => $asset->set('alt', $value),
                'title' => $asset->set('title', $value),
                default => $asset->set($key, $value),
            };
        }
        $this->persistStatamic(fn () => $asset->save());
        // Clear caches
        $this->clearCachesAfterWrite(['stache']);

        return [
            'asset' => [
                ...$this->assetRecord($asset),
                'updated' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deleteAsset(array $arguments): array
    {
        $container = is_string($arguments['container'] ?? null) ? $arguments['container'] : null;
        $path = is_string($arguments['path'] ?? null) ? $arguments['path'] : null;
        if (! $container || ! $path) {
            return $this->createErrorResponse('Both container and path are required')->toArray();
        }
        $asset = Asset::find($container . '::' . $path);
        if (! $asset) {
            return $this->missingResource('Asset', "{$container}::{$path}", 'path')->toArray();
        }
        $snapshot = $this->assetRecord($asset);
        $this->persistStatamic(fn () => $asset->delete());
        // Clear caches
        $this->clearCachesAfterWrite(['stache']);

        return [
            'asset' => [
                ...$snapshot,
                'deleted' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function moveAsset(array $arguments): array
    {
        $container = is_string($arguments['container'] ?? null) ? $arguments['container'] : null;
        $path = is_string($arguments['path'] ?? null) ? $arguments['path'] : null;
        $destination = is_string($arguments['destination'] ?? null) ? $arguments['destination'] : null;
        if (! $container || ! $path || ! $destination) {
            return $this->createErrorResponse('Container, path, and destination are required')->toArray();
        }
        $containerObj = AssetContainer::find($container);
        if (! $containerObj) {
            return $this->missingResource('Asset container', $container, 'container', AssetContainer::all()->map->handle()->all(), $arguments)->toArray();
        }
        // Check container permissions
        if (! $this->containerAllows($containerObj, 'move')) {
            return $this->createErrorResponse("Container '{$container}' does not allow moving assets")->toArray();
        }
        $asset = Asset::find($container . '::' . $path);
        if (! $asset) {
            return $this->missingResource('Asset', "{$container}::{$path}", 'path')->toArray();
        }
        $target = $this->assetDestination($asset, $destination);
        if (is_string($target)) {
            return $this->createErrorResponse($target)->toArray();
        }
        $oldPath = $asset->path();
        $this->persistStatamic(function () use ($asset, $target, $oldPath): void {
            $asset->move($target['folder'], $target['filename']);
            // Native move() ignores a filesystem adapter's false return.
            // Verify the file rather than reporting a metadata-only move.
            $disk = $asset->container()->disk();
            if (! $disk->exists($asset->path()) || ($oldPath !== $asset->path() && $disk->exists($oldPath))) {
                throw new ToolFailure(ErrorCode::OutcomeUnknown, 'Asset move could not be verified. Reconcile the source and destination before retrying.');
            }
        });
        $this->persistStatamic(fn () => $asset->save());
        // Clear caches
        $this->clearCachesAfterWrite(['stache']);

        return [
            'asset' => [
                ...$this->assetRecord($asset),
                'old_path' => $oldPath,
                'new_path' => $asset->path(),
                'moved' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function copyAsset(array $arguments): array
    {
        $container = is_string($arguments['container'] ?? null) ? $arguments['container'] : null;
        $path = is_string($arguments['path'] ?? null) ? $arguments['path'] : null;
        $destination = is_string($arguments['destination'] ?? null) ? $arguments['destination'] : null;
        if (! $container || ! $path || ! $destination) {
            return $this->createErrorResponse('Container, path, and destination are required')->toArray();
        }
        $asset = Asset::find($container . '::' . $path);
        if (! $asset) {
            return $this->missingResource('Asset', "{$container}::{$path}", 'path')->toArray();
        }
        $target = $this->assetDestination($asset, $destination);
        if (is_string($target)) {
            return $this->createErrorResponse($target)->toArray();
        }
        // Statamic's Asset has no copy(); this used to call one and fail
        // every time. Copy the file, then give the new asset the same data.
        $newPath = ltrim($target['folder'] . '/' . AssetUploader::getSafeFilename($target['filename']) . '.' . $asset->extension(), '/');
        $disk = $asset->container()->disk();
        if ($disk->exists($newPath)) {
            return $this->createErrorResponse("An asset already exists at {$container}::{$newPath}")->toArray();
        }
        $this->persistStatamic(fn () => $disk->copy($asset->path(), $newPath));
        $newAsset = $asset->container()->makeAsset($newPath);
        $newAsset->data($asset->data()->all());
        $this->persistStatamic(fn () => $newAsset->save());
        // Clear caches
        $this->clearCachesAfterWrite(['stache']);

        return [
            'original' => [
                'id' => $asset->id(),
                'path' => $asset->path(),
            ],
            'copy' => [
                ...$this->assetRecord($newAsset),
                'copied' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function uploadAsset(array $arguments): array
    {
        $container = is_string($arguments['container'] ?? null) ? $arguments['container'] : null;
        $file_path = is_string($arguments['file_path'] ?? null) ? $arguments['file_path'] : null;
        $filename = is_string($arguments['filename'] ?? null) ? $arguments['filename'] : null;
        $content = is_string($arguments['content'] ?? null) ? $arguments['content'] : null;
        $sourceUrl = is_string($arguments['source_url'] ?? null) && $arguments['source_url'] !== '' ? $arguments['source_url'] : null;
        $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
        if (! $container) {
            return $this->createErrorResponse('Container is required')->toArray();
        }
        if (count(array_filter([$file_path, $content, $sourceUrl])) > 1) {
            return $this->createErrorResponse('Send one of source_url, content or file_path, not several')->toArray();
        }
        $folder = $this->assetFolder($arguments['folder'] ?? null);
        if ($folder === null) {
            return $this->createErrorResponse("folder must be a path inside the container, such as 'uploads/cases', without '.' or '..' segments")->toArray();
        }
        if (! $file_path && ! $content && ! $sourceUrl) {
            return $this->createErrorResponse('One of source_url (fetch from a URL), content with encoding+filename (base64), or file_path (local CLI) is required')->toArray();
        }
        $assetContainer = AssetContainer::find($container);
        if (! $assetContainer) {
            return $this->missingResource('Asset container', $container, 'container', AssetContainer::all()->map->handle()->all(), $arguments)->toArray();
        }
        if ($sourceUrl !== null) {
            $stored = $this->importFromUrl($assetContainer, $folder, $sourceUrl, $filename);
            if (is_string($stored)) {
                return $this->createErrorResponse($stored)->toArray();
            }
            $asset = $stored;
        } elseif ($content) {
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

            $maxSizeBytes = McpConfig::int('security.max_upload_size', 10 * 1024 * 1024);
            if (strlen($decodedContent) > $maxSizeBytes) {
                $maxSizeMB = round($maxSizeBytes / 1024 / 1024, 1);

                return $this->createErrorResponse("File size exceeds maximum allowed size of {$maxSizeMB}MB")->toArray();
            }

            $stored = $this->storeContent($assetContainer, $folder, $filename, $decodedContent);
            if (is_string($stored)) {
                return $this->createErrorResponse($stored)->toArray();
            }
            $asset = $stored;
        } else {
            // Local file upload (CLI MCP clients with filesystem access)
            if ($file_path === null) {
                return $this->createErrorResponse('file_path is required')->toArray();
            }

            // file_path reads a server-local file; it is for the trusted
            // local CLI server only. A remote/web caller could otherwise
            // copy files out of storage/app into a container, so refuse it
            // outside CLI context — remote clients use source_url or content.
            if (! $this->isCliContext()) {
                return $this->createErrorResponse('file_path uploads are only available to the local CLI server; use source_url or content instead.')->toArray();
            }

            $realPath = realpath($file_path);
            $allowedBase = realpath(storage_path('app'));
            if ($realPath === false || ! $allowedBase || ! str_starts_with($realPath, $allowedBase . DIRECTORY_SEPARATOR)) {
                return $this->createErrorResponse('file_path must be within the storage/app directory for security')->toArray();
            }

            if (! file_exists($file_path)) {
                return $this->missingResource('File', $file_path, 'file_path')->toArray();
            }

            $filename = $filename ?? basename($file_path);

            if ($filename !== basename($filename)) {
                return $this->createErrorResponse('Filename must not contain path separators or traversal sequences')->toArray();
            }

            $stored = $this->storeFile($assetContainer, $folder, $filename, $realPath);
            if (is_string($stored)) {
                return $this->createErrorResponse($stored)->toArray();
            }
            $asset = $stored;
        }
        // Set additional data
        foreach ($data as $key => $value) {
            $asset->set($key, $value);
        }
        $this->persistStatamic(fn () => $asset->save());
        // Clear caches
        $this->clearCachesAfterWrite(['stache']);

        return [
            'asset' => $this->assetRecord($asset),
            'uploaded' => true,
        ];
    }

    /**
     * Fetch a file from a URL and store it as an asset.
     *
     * For remote clients, which can name a file on the web but cannot send
     * megabytes of base64 through a tool call (#67). Every URL a client
     * names is fetched through OutboundFetcher, so it cannot reach the
     * server's own network.
     *
     * @return AssetContract|string the asset, or why it was refused
     */
    private function importFromUrl(AssetContainerContract $container, string $folder, string $url, ?string $filename): AssetContract|string
    {
        if (! config('statamic.mcp.security.url_import.enabled', true)) {
            return 'Importing from a URL is switched off on this site (security.url_import.enabled).';
        }

        $filename ??= rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));

        if ($filename === '' || pathinfo($filename, PATHINFO_EXTENSION) === '') {
            return 'The URL does not end in a filename with an extension; pass filename as well.';
        }

        if ($filename !== basename($filename)) {
            return 'Filename must not contain path separators or traversal sequences';
        }

        // Refuse the extension before downloading anything; storeFile()
        // checks again against the file it actually stores.
        $probe = tempnam(sys_get_temp_dir(), 'statamic_mcp_probe_');
        if ($probe === false) {
            return 'Failed to create temporary file for upload';
        }

        try {
            $refused = $this->disallowedFile(new UploadedFile($probe, $filename, null, null, true));
        } finally {
            @unlink($probe);
        }

        if ($refused !== null) {
            return $refused;
        }

        /** @var array<int, string> $allowedHosts */
        $allowedHosts = config('statamic.mcp.security.url_import.allowed_hosts', []);
        /** @var int $timeout */
        $timeout = config('statamic.mcp.security.url_import.timeout', 15);
        $maxBytes = McpConfig::int('security.max_upload_size', 10 * 1024 * 1024);

        $fetcher = new OutboundFetcher(blockPrivate: (bool) config('statamic.mcp.security.url_import.block_private_ips', true));

        try {
            $fetched = $fetcher->get(
                $url,
                $maxBytes,
                $timeout,
                headers: ['User-Agent' => 'statamic-mcp (asset import)', 'Accept' => '*/*'],
                allowHost: $allowedHosts === [] ? null : fn (string $host): bool => Str::is($allowedHosts, $host),
            );
        } catch (FetchRefused $e) {
            return $e->getMessage();
        }

        return $this->storeContent($container, $folder, $filename, $fetched->body);
    }

    /**
     * Write bytes to a temporary file and store them as an asset.
     *
     * @return AssetContract|string the asset, or why it was refused
     */
    private function storeContent(AssetContainerContract $container, string $folder, string $filename, string $bytes): AssetContract|string
    {
        $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'statamic_mcp_upload_' . bin2hex(random_bytes(16));

        if (file_put_contents($tempPath, $bytes) === false) {
            return 'Failed to create temporary file for upload';
        }

        try {
            return $this->storeFile($container, $folder, $filename, $tempPath);
        } finally {
            if (file_exists($tempPath) && ! unlink($tempPath)) {
                Log::warning('Failed to clean up MCP temp file', ['path' => $tempPath]);
            }
        }
    }

    /**
     * Store a local file as an asset, the way a Control Panel upload does.
     *
     * Every path that puts a new file into a container ends here, because
     * this is where Statamic's AllowedFile rule runs. The Control Panel
     * applies it to every upload; without it a token with assets:write
     * could write `shell.php` into a public container.
     *
     * @return AssetContract|string the asset, or why it was refused
     */
    private function storeFile(AssetContainerContract $container, string $folder, string $filename, string $sourcePath): AssetContract|string
    {
        $file = new UploadedFile($sourcePath, $filename, $this->detectMimeType($sourcePath), null, true);

        $refused = $this->disallowedFile($file);
        if ($refused !== null) {
            return $refused;
        }

        $asset = $this->persistStatamic(fn () => $container->makeAsset($folder === '' ? $filename : "{$folder}/{$filename}")->upload($file));

        if (! $asset instanceof AssetContract) {
            return 'The upload was cancelled by an AssetCreating listener.';
        }

        return $asset;
    }

    /**
     * Statamic's AllowedFile verdict on a file's name, or null when allowed.
     */
    private function disallowedFile(UploadedFile $file): ?string
    {
        if (! Validator::make(['file' => $file], ['file' => [new AllowedFile]])->fails()) {
            return null;
        }

        $extension = $file->getClientOriginalExtension();

        return $extension === ''
            ? "Files without an extension cannot be uploaded: '{$file->getClientOriginalName()}'."
            : "Files with the extension '.{$extension}' cannot be uploaded. A site can allow it in statamic.assets.additional_uploadable_extensions.";
    }

    private function detectMimeType(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $detected = finfo_file($finfo, $path);
                finfo_close($finfo);

                if ($detected !== false) {
                    return $detected;
                }
            }
        }

        return (function_exists('mime_content_type') ? mime_content_type($path) : false) ?: 'application/octet-stream';
    }

    /**
     * Split a move or copy destination into the folder and the filename
     * (without extension) Statamic's move() takes.
     *
     * `destination` is the asset's new path — "archive/old-hero.jpg". A path
     * ending in a slash, or without an extension, names a folder and keeps
     * the filename, which is how it was read before. The extension cannot
     * change: that is what keeps a stored image from becoming `.php`.
     *
     * @return array{folder: string, filename: string}|string the parts, or why it was refused
     */
    private function assetDestination(AssetContract $asset, string $destination): array|string
    {
        $extension = pathinfo($destination, PATHINFO_EXTENSION);

        if (str_ends_with($destination, '/') || $extension === '') {
            $folder = $this->assetFolder($destination);
            $filename = $asset->filename();
        } else {
            if (strtolower($extension) !== strtolower($asset->extension())) {
                return "The destination must keep the extension '.{$asset->extension()}'.";
            }

            $directory = dirname($destination);
            $folder = $this->assetFolder($directory === '.' ? '' : $directory);
            $filename = pathinfo($destination, PATHINFO_FILENAME);
        }

        if ($folder === null || $filename === '') {
            return "destination must be a path inside the container, such as 'archive/hero.jpg', without '.' or '..' segments";
        }

        return ['folder' => $folder, 'filename' => $filename];
    }

    /**
     * A folder inside the container, without leading or trailing slashes;
     * '' for the root, null when it is not a plain relative path.
     */
    private function assetFolder(mixed $folder): ?string
    {
        if ($folder === null || $folder === '') {
            return '';
        }

        if (! is_string($folder) || str_contains($folder, '\\') || str_contains($folder, "\0")) {
            return null;
        }

        $segments = explode('/', trim($folder, '/'));

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return implode('/', $segments);
    }

    /**
     * Get required permissions for action.
     *
     * Mirrors Statamic's AssetPolicy and AssetContainerPolicy, whose before()
     * lets "configure asset containers" do anything a per-container
     * permission allows.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<int, string|array<int, string>>
     */
    protected function getRequiredPermissions(string $action, array $arguments): array
    {
        $type = $arguments['resource_type'] ?? '';
        $container = is_string($arguments['container'] ?? null) ? $arguments['container'] : '';

        $perContainer = fn (string $handle, string $ability): array => $handle !== ''
            ? [["{$ability} {$handle} assets", 'configure asset containers']]
            : ['configure asset containers'];

        if ($type === 'container') {
            $handle = $container;

            return match ($action) {
                // listContainers() filters per container.
                'list' => [],
                'get' => $perContainer($handle, 'view'),
                'create', 'update', 'delete' => ['configure asset containers'],
                default => ['super'],
            };
        }

        if ($type === 'asset') {
            return match ($action) {
                'list', 'get' => $perContainer($container, 'view'),
                'upload', 'create' => $perContainer($container, 'upload'),
                'update' => $perContainer($container, 'edit'),
                'move' => $perContainer($container, 'move'),
                // A copy is a new asset; Statamic's DuplicateAsset authorizes store.
                'copy' => $perContainer($container, 'upload'),
                'rename' => $perContainer($container, 'rename'),
                'delete' => $perContainer($container, 'delete'),
                default => ['super'],
            };
        }

        return ['super'];
    }

    /**
     * Whether a container belongs in an unscoped container list: the resource
     * policy must allow reading it and, in web context, the caller must be able
     * to view it.
     */
    private function canListContainer(string $handle): bool
    {
        /** @var ResourcePolicy $policy */
        $policy = app(ResourcePolicy::class);

        if (! $policy->canAccess($this->getDomain(), $handle, 'read')) {
            return false;
        }

        if ($this->isCliContext()) {
            return true;
        }

        /** @var User|null $user */
        $user = auth()->user();

        return $user !== null
            && ($user->isSuper() || $user->hasPermission('configure asset containers') || $user->hasPermission("view {$handle} assets"));
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

    /**
     * The resource policy must see the container the action acts on, which
     * in v4 is always `container`: for an asset, the container it lives in;
     * for a container action, the container itself (a create's new handle
     * included). v3 read `handle` or `data.handle` for containers, and the
     * priority between them let an allowed name cover a denied one.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function resolveResourceHandle(array $arguments): ?string
    {
        $raw = $arguments['container'] ?? null;

        return is_string($raw) && $raw !== '' ? $raw : null;
    }
}
