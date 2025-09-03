<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Entries;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Entry;

#[Title('Publish Statamic Entry')]
class PublishEntryTool extends BaseStatamicTool
{
    use ClearsCaches;

    protected function getToolName(): string
    {
        return 'statamic.entries.publish';
    }

    protected function getToolDescription(): string
    {
        return 'Publish an entry to make it publicly accessible';
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

        if ($entry->published()) {
            return [
                'id' => $id,
                'message' => 'Entry is already published',
                'entry' => [
                    'id' => $entry->id(),
                    'title' => $entry->get('title'),
                    'published' => true,
                    'status' => $entry->status(),
                ],
            ];
        }

        try {
            $entry->published(true);
            $entry->save();

            // Clear caches
            $cacheTypes = $this->getRecommendedCacheTypes('content_change');
            $cacheResult = $this->clearStatamicCaches($cacheTypes);

            return [
                'entry' => [
                    'id' => $entry->id(),
                    'title' => $entry->get('title'),
                    'slug' => $entry->slug(),
                    'url' => $entry->url(),
                    'published' => $entry->published(),
                    'status' => $entry->status(),
                    'collection' => $entry->collection()->handle(),
                ],
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not publish entry: ' . $e->getMessage())->toArray();
        }
    }
}
