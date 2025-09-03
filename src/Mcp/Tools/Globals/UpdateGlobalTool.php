<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Globals;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\GlobalSet;

#[Title('Update Statamic Global Values')]
class UpdateGlobalTool extends BaseStatamicTool
{
    use ClearsCaches;
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.globals.update';
    }

    protected function getToolDescription(): string
    {
        return 'Update global variable values for a specific site';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('handle')
            ->description('Global set handle')
            ->required()
            ->raw('values', ['type' => 'object'])
            ->description('Key-value pairs of global variables to update')
            ->required()
            ->string('site')
            ->description('Site to update values for (defaults to default site)')
            ->optional()
            ->boolean('merge')
            ->description('Merge with existing values instead of replacing')
            ->optional()
            ->boolean('dry_run')
            ->description('Validate without actually updating')
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
        $values = $arguments['values'];
        $site = $arguments['site'] ?? null;
        $merge = $arguments['merge'] ?? true;
        $dryRun = $arguments['dry_run'] ?? false;

        try {
            $globalSet = GlobalSet::find($handle);
            if (! $globalSet) {
                return $this->createErrorResponse("Global set '{$handle}' not found")->toArray();
            }

            // Determine target site
            $targetSite = $site ?? $globalSet->sites()->first();
            if (! in_array($targetSite, $globalSet->sites()->all())) {
                return $this->createErrorResponse("Site '{$targetSite}' is not configured for global set '{$handle}'")->toArray();
            }

            // Get current variables
            $variables = $globalSet->in($targetSite);
            $currentValues = $variables ? $variables->data()->toArray() : [];

            // Prepare new values
            $newValues = $merge ? array_merge($currentValues, $values) : $values;

            // Validate against blueprint if available
            $validationErrors = [];
            if ($globalSet->blueprint()) {
                $blueprint = $globalSet->blueprint();
                foreach ($blueprint->fields()->all() as $field) {
                    $handle = $field->handle();

                    // Check required fields
                    if ($field->isRequired() && (! isset($newValues[$handle]) || $newValues[$handle] === '')) {
                        $validationErrors[] = "Field '{$handle}' is required";
                    }
                }
            }

            if (! empty($validationErrors)) {
                return $this->createErrorResponse('Validation failed: ' . implode(', ', $validationErrors))->toArray();
            }

            $changes = [];
            foreach ($values as $key => $value) {
                if (! isset($currentValues[$key]) || $currentValues[$key] !== $value) {
                    $changes[$key] = [
                        'from' => $currentValues[$key] ?? null,
                        'to' => $value,
                    ];
                }
            }

            if ($dryRun) {
                return [
                    'dry_run' => true,
                    'would_update' => $changes,
                    'current_values' => $currentValues,
                    'new_values' => $newValues,
                    'site' => $targetSite,
                ];
            }

            // Update the variables
            if (! $variables) {
                $variables = $globalSet->makeLocalization($targetSite);
            }

            $variables->data($newValues);
            $variables->save();

            // Clear caches
            $cacheTypes = $this->getRecommendedCacheTypes('content_change');
            $cacheResult = $this->clearStatamicCaches($cacheTypes);

            return [
                'global' => [
                    'handle' => $handle,
                    'site' => $targetSite,
                    'values' => $newValues,
                    'updated_at' => now()->toISOString(),
                ],
                'changes' => $changes,
                'changes_count' => count($changes),
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not update global values: ' . $e->getMessage())->toArray();
        }
    }
}
