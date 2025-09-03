<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Assets;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\AssetContainer;

#[Title('Create Asset Container')]
class CreateAssetTool extends BaseStatamicTool
{
    use ClearsCaches;
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.assets.create';
    }

    protected function getToolDescription(): string
    {
        return 'Create a new asset container with specified configuration';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('handle')
            ->description('Container handle')
            ->required()
            ->string('title')
            ->description('Container title')
            ->optional()
            ->string('disk')
            ->description('Storage disk (local, s3, etc.)')
            ->optional()
            ->string('path')
            ->description('Base path for assets')
            ->optional()
            ->boolean('allow_uploads')
            ->description('Allow file uploads')
            ->optional()
            ->boolean('allow_downloading')
            ->description('Allow file downloads')
            ->optional()
            ->boolean('allow_renaming')
            ->description('Allow file renaming')
            ->optional()
            ->boolean('allow_moving')
            ->description('Allow file moving')
            ->optional()
            ->boolean('create_folders')
            ->description('Allow folder creation')
            ->optional()
            ->string('validation_rules')
            ->description('File validation rules (JSON string)')
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

        if (AssetContainer::find($handle)) {
            return $this->createErrorResponse("Container '{$handle}' already exists")->toArray();
        }

        try {
            $container = AssetContainer::make($handle);

            if (isset($arguments['title'])) {
                $container->title($arguments['title']);
            }

            if (isset($arguments['disk'])) {
                $container->disk($arguments['disk']);
            } else {
                $container->disk('local');
            }

            if (isset($arguments['path'])) {
                $container->path($arguments['path']);
            }

            if (isset($arguments['allow_uploads'])) {
                $container->allowUploads($arguments['allow_uploads']);
            }

            if (isset($arguments['allow_downloading'])) {
                $container->allowDownloading($arguments['allow_downloading']);
            }

            if (isset($arguments['allow_renaming'])) {
                $container->allowRenaming($arguments['allow_renaming']);
            }

            if (isset($arguments['allow_moving'])) {
                $container->allowMoving($arguments['allow_moving']);
            }

            if (isset($arguments['create_folders'])) {
                $container->createFolders($arguments['create_folders']);
            }

            if (isset($arguments['validation_rules'])) {
                try {
                    $rules = json_decode($arguments['validation_rules'], true);
                    if ($rules) {
                        $container->validationRules($rules);
                    }
                } catch (\Exception $e) {
                    return $this->createErrorResponse('Invalid validation rules JSON: ' . $e->getMessage())->toArray();
                }
            }

            $container->save();

            // Clear caches
            $cacheTypes = $this->getRecommendedCacheTypes('structure_change');
            $cacheResult = $this->clearStatamicCaches($cacheTypes);

            return [
                'container' => [
                    'handle' => $container->handle(),
                    'title' => $container->title(),
                    'disk' => $container->diskHandle(),
                    'path' => $container->path(),
                ],
                'message' => "Asset container '{$handle}' created successfully",
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not create container: ' . $e->getMessage())->toArray();
        }
    }
}
