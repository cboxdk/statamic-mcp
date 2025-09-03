<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Assets;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\AssetContainer;

#[Title('Update Asset Container')]
class UpdateAssetTool extends BaseStatamicTool
{
    use ClearsCaches;
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.assets.update';
    }

    protected function getToolDescription(): string
    {
        return 'Update an existing asset container configuration';
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

        $container = AssetContainer::find($handle);
        if (! $container) {
            return $this->createErrorResponse("Container '{$handle}' not found")->toArray();
        }

        try {
            $updated = [];

            if (isset($arguments['title'])) {
                $container->title($arguments['title']);
                $updated['title'] = $arguments['title'];
            }

            if (isset($arguments['disk'])) {
                $container->disk($arguments['disk']);
                $updated['disk'] = $arguments['disk'];
            }

            if (isset($arguments['path'])) {
                $container->path($arguments['path']);
                $updated['path'] = $arguments['path'];
            }

            if (isset($arguments['allow_uploads'])) {
                $container->allowUploads($arguments['allow_uploads']);
                $updated['allow_uploads'] = $arguments['allow_uploads'];
            }

            if (isset($arguments['allow_downloading'])) {
                $container->allowDownloading($arguments['allow_downloading']);
                $updated['allow_downloading'] = $arguments['allow_downloading'];
            }

            if (isset($arguments['allow_renaming'])) {
                $container->allowRenaming($arguments['allow_renaming']);
                $updated['allow_renaming'] = $arguments['allow_renaming'];
            }

            if (isset($arguments['allow_moving'])) {
                $container->allowMoving($arguments['allow_moving']);
                $updated['allow_moving'] = $arguments['allow_moving'];
            }

            if (isset($arguments['create_folders'])) {
                $container->createFolders($arguments['create_folders']);
                $updated['create_folders'] = $arguments['create_folders'];
            }

            if (isset($arguments['validation_rules'])) {
                try {
                    $rules = json_decode($arguments['validation_rules'], true);
                    if ($rules) {
                        $container->validationRules($rules);
                        $updated['validation_rules'] = $rules;
                    }
                } catch (\Exception $e) {
                    return $this->createErrorResponse('Invalid validation rules JSON: ' . $e->getMessage())->toArray();
                }
            }

            if (empty($updated)) {
                return $this->createErrorResponse('No fields provided to update')->toArray();
            }

            $container->save();

            // Clear caches
            $cacheTypes = $this->getRecommendedCacheTypes('structure_change');
            $cacheResult = $this->clearStatamicCaches($cacheTypes);

            return [
                'handle' => $handle,
                'updated_fields' => $updated,
                'message' => "Asset container '{$handle}' updated successfully",
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not update container: ' . $e->getMessage())->toArray();
        }
    }
}
