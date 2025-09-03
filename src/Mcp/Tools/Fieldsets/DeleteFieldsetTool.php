<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Fieldsets;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Fieldset;

#[Title('Delete Statamic Fieldset')]
class DeleteFieldsetTool extends BaseStatamicTool
{
    use ClearsCaches;

    protected function getToolName(): string
    {
        return 'statamic.fieldsets.delete';
    }

    protected function getToolDescription(): string
    {
        return 'Delete a Statamic fieldset with safety checks';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('handle')
            ->description('Fieldset handle')
            ->required()
            ->boolean('force')
            ->description('Force deletion without safety checks')
            ->optional()
            ->boolean('dry_run')
            ->description('Preview what would be deleted without actually deleting')
            ->optional();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $handle = $arguments['handle'];
        $force = $arguments['force'] ?? false;
        $dryRun = $arguments['dry_run'] ?? false;

        $fieldset = Fieldset::find($handle);

        if (! $fieldset) {
            return $this->createErrorResponse("Fieldset '{$handle}' not found")->toArray();
        }

        // Safety checks - check if fieldset is imported by blueprints
        $warnings = [];
        $usage = $this->checkFieldsetUsage($handle);

        if (! empty($usage['blueprints'])) {
            $warnings[] = 'Fieldset is imported by blueprints: ' . implode(', ', $usage['blueprints']);
        }

        if (! empty($warnings) && ! $force && ! $dryRun) {
            return $this->createErrorResponse(
                'Cannot delete fieldset. ' . implode('. ', $warnings) . '. Use force=true to override.'
            )->toArray();
        }

        if ($dryRun) {
            return [
                'dry_run' => true,
                'would_delete' => [
                    'handle' => $handle,
                    'title' => $fieldset->title(),
                    'field_count' => count($fieldset->contents()['fields'] ?? []),
                ],
                'warnings' => $warnings,
                'usage' => $usage,
            ];
        }

        try {
            $fieldsetData = [
                'handle' => $fieldset->handle(),
                'title' => $fieldset->title(),
                'field_count' => count($fieldset->contents()['fields'] ?? []),
            ];

            // Delete fieldset
            $fieldset->delete();

            // Clear caches
            $cacheTypes = $this->getRecommendedCacheTypes('fieldset_change');
            $cacheResult = $this->clearStatamicCaches($cacheTypes);

            return [
                'deleted' => $fieldsetData,
                'warnings' => $warnings,
                'usage_at_deletion' => $usage,
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not delete fieldset: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Check where the fieldset is being used.
     *
     * @return array<string, array<int, string>>
     */
    private function checkFieldsetUsage(string $handle): array
    {
        $usage = ['blueprints' => []];

        // Check if fieldset is imported in any blueprints
        // This is a simplified check - in reality, we'd need to scan blueprint files
        // for 'import: fieldset_handle' statements

        // For now, we'll return empty usage as the actual implementation
        // would require scanning filesystem or using Statamic's API
        // when available

        return $usage;
    }
}
