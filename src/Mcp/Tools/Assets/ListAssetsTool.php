<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Assets;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\AssetContainer;

#[Title('List Asset Containers')]
#[IsReadOnly]
class ListAssetsTool extends BaseStatamicTool
{
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.assets.list';
    }

    protected function getToolDescription(): string
    {
        return 'List all Statamic asset containers with their configuration';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema;
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
        $containers = AssetContainer::all();
        $containerList = [];

        foreach ($containers as $container) {
            $containerList[] = [
                'handle' => $container->handle(),
                'title' => $container->title(),
                'disk' => $container->diskHandle(),
                'path' => $container->path(),
                'url' => $container->url(),
                'allow_uploads' => $container->allowUploads(),
                'allow_downloading' => $container->allowDownloading(),
                'allow_renaming' => $container->allowRenaming(),
                'allow_moving' => $container->allowMoving(),
                'create_folders' => $container->createFolders(),
                'validation_rules' => $container->validationRules(),
                'asset_count' => $container->assets()->count(),
            ];
        }

        return [
            'containers' => $containerList,
            'total_containers' => count($containerList),
        ];
    }
}
