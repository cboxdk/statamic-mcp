<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Assets;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\AssetContainer;

#[Title('Get Asset Container')]
#[IsReadOnly]
class GetAssetTool extends BaseStatamicTool
{
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.assets.get';
    }

    protected function getToolDescription(): string
    {
        return 'Get detailed information about a specific asset container';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('handle')
            ->description('Container handle')
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
        $handle = $arguments['handle'];

        $container = AssetContainer::find($handle);
        if (! $container) {
            return $this->createErrorResponse("Container '{$handle}' not found")->toArray();
        }

        return [
            'container' => [
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
                'blueprint' => $container->blueprint()?->handle(),
            ],
        ];
    }
}
