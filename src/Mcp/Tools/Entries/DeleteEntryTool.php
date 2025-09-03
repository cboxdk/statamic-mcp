<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Entries;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Entry;

#[Title('Delete Statamic Entry')]
class DeleteEntryTool extends BaseStatamicTool
{
    use ClearsCaches;

    protected function getToolName(): string
    {
        return 'statamic.entries.delete';
    }

    protected function getToolDescription(): string
    {
        return 'Delete an entry with safety checks';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('id')
            ->description('Entry ID')
            ->required()
            ->boolean('force')
            ->description('Force deletion without safety checks')
            ->optional()
            ->boolean('dry_run')
            ->description('Preview what would be deleted')
            ->optional();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $id = $arguments['id'];
        $force = $arguments['force'] ?? false;
        $dryRun = $arguments['dry_run'] ?? false;

        $entry = Entry::find($id);
        if (! $entry) {
            return $this->createErrorResponse("Entry '{$id}' not found")->toArray();
        }

        // Safety checks
        $warnings = [];

        if ($entry->published()) {
            $warnings[] = 'Entry is published and publicly accessible';
        }

        if (! empty($warnings) && ! $force && ! $dryRun) {
            return $this->createErrorResponse(
                'Cannot delete entry. ' . implode('. ', $warnings) . '. Use force=true to override.'
            )->toArray();
        }

        if ($dryRun) {
            return [
                'dry_run' => true,
                'would_delete' => [
                    'id' => $entry->id(),
                    'title' => $entry->get('title'),
                    'slug' => $entry->slug(),
                    'collection' => $entry->collection()->handle(),
                    'published' => $entry->published(),
                    'url' => $entry->url(),
                ],
                'warnings' => $warnings,
            ];
        }

        try {
            $entryData = [
                'id' => $entry->id(),
                'title' => $entry->get('title'),
                'slug' => $entry->slug(),
                'collection' => $entry->collection()->handle(),
                'published' => $entry->published(),
                'url' => $entry->url(),
            ];

            // Delete entry
            $entry->delete();

            // Clear caches
            $cacheTypes = $this->getRecommendedCacheTypes('content_change');
            $cacheResult = $this->clearStatamicCaches($cacheTypes);

            return [
                'deleted' => $entryData,
                'warnings' => $warnings,
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not delete entry: ' . $e->getMessage())->toArray();
        }
    }
}
