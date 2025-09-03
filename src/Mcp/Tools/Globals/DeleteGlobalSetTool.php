<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Globals;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Stache;

#[Title('Delete Global Set')]
class DeleteGlobalSetTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.globals.sets.delete';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Delete a global set and all its values across all sites';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('handle')
            ->description('Handle of the global set to delete')
            ->required()
            ->boolean('confirm_deletion')
            ->description('Confirmation that you want to delete the global set (required)')
            ->required()
            ->boolean('backup_values')
            ->description('Create a backup of values before deletion')
            ->optional();
    }

    /**
     * Execute the tool logic.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $handle = $arguments['handle'];
        $confirmDeletion = $arguments['confirm_deletion'];
        $backupValues = $arguments['backup_values'] ?? false;

        try {
            if (! $confirmDeletion) {
                return $this->createErrorResponse('Deletion not confirmed', [
                    'message' => 'You must set confirm_deletion to true to delete a global set',
                    'warning' => 'This action cannot be undone and will delete all values across all sites',
                ])->toArray();
            }

            $globalSet = GlobalSet::findByHandle($handle);

            if (! $globalSet) {
                return $this->createErrorResponse("Global set '{$handle}' not found", [
                    'available_sets' => GlobalSet::all()->map->handle()->all(),
                ])->toArray();
            }

            // Collect information before deletion
            $deletionInfo = [
                'handle' => $handle,
                'title' => $globalSet->title(),
                'sites' => $globalSet->sites()->all(),
                'had_blueprint' => $globalSet->blueprint() !== null,
                'blueprint_handle' => $globalSet->blueprint()?->handle(),
            ];

            // Backup values if requested
            $backupData = [];
            if ($backupValues) {
                foreach ($globalSet->sites() as $siteHandle) {
                    $localizedSet = $globalSet->in($siteHandle);
                    if ($localizedSet) {
                        $backupData[$siteHandle] = $localizedSet->data()->all();
                    }
                }
            }

            // Delete the global set
            $globalSet->delete();

            // Clear relevant caches
            Stache::clear();

            $result = [
                'success' => true,
                'deleted_global_set' => $deletionInfo,
                'deleted_at' => now()->toISOString(),
                'sites_affected' => $deletionInfo['sites'],
            ];

            if ($backupValues && ! empty($backupData)) {
                $result['backup_data'] = $backupData;
                $result['backup_info'] = [
                    'total_sites_backed_up' => count($backupData),
                    'total_fields_backed_up' => array_sum(array_map('count', $backupData)),
                ];
            }

            return $result;

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to delete global set: ' . $e->getMessage())->toArray();
        }
    }
}
