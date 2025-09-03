<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Entries;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Entry;

#[Title('Unpublish Statamic Entry')]
class UnpublishEntryTool extends BaseStatamicTool
{
    use ClearsCaches;

    protected function getToolName(): string
    {
        return 'statamic.entries.unpublish';
    }

    protected function getToolDescription(): string
    {
        return 'Unpublish an entry to make it a draft';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('id')
            ->description('Entry ID')
            ->required();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $id = $arguments['id'];

        $entry = Entry::find($id);
        if (! $entry) {
            return $this->createErrorResponse("Entry '{$id}' not found")->toArray();
        }

        if (! $entry->published()) {
            return [
                'id' => $id,
                'message' => 'Entry is already unpublished',
                'entry' => [
                    'id' => $entry->id(),
                    'title' => $entry->get('title'),
                    'published' => false,
                    'status' => $entry->status(),
                ],
            ];
        }

        try {
            $entry->published(false);
            $entry->save();

            // Clear caches
            $cacheTypes = $this->getRecommendedCacheTypes('content_change');
            $cacheResult = $this->clearStatamicCaches($cacheTypes);

            return [
                'entry' => [
                    'id' => $entry->id(),
                    'title' => $entry->get('title'),
                    'slug' => $entry->slug(),
                    'published' => $entry->published(),
                    'status' => $entry->status(),
                    'collection' => $entry->collection()->handle(),
                ],
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not unpublish entry: ' . $e->getMessage())->toArray();
        }
    }
}
