<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Entries;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

#[Title('List Statamic Entries')]
#[IsReadOnly]
class ListEntresTool extends BaseStatamicTool
{
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.entries.list';
    }

    protected function getToolDescription(): string
    {
        return 'List entries from a specific collection with filtering and pagination';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('collection')
            ->description('Collection handle')
            ->required()
            ->string('filter')
            ->description('Filter entries by title or slug')
            ->optional()
            ->integer('limit')
            ->description('Limit the number of results (default: 50)')
            ->optional()
            ->integer('offset')
            ->description('Offset for pagination')
            ->optional()
            ->boolean('include_data')
            ->description('Include entry data')
            ->optional()
            ->string('status')
            ->description('Filter by status (published, draft)')
            ->optional();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $collectionHandle = $arguments['collection'];
        $filter = $arguments['filter'] ?? null;
        $limit = $arguments['limit'] ?? 50;
        $offset = $arguments['offset'] ?? 0;
        $includeData = $arguments['include_data'] ?? false;
        $status = $arguments['status'] ?? null;

        $collection = Collection::find($collectionHandle);
        if (! $collection) {
            return $this->createErrorResponse("Collection '{$collectionHandle}' not found")->toArray();
        }

        try {
            $query = Entry::query()->where('collection', $collectionHandle);

            // Apply status filter
            if ($status === 'published') {
                $query->where('published', true);
            } elseif ($status === 'draft') {
                $query->where('published', false);
            }

            // Apply text filter
            if ($filter) {
                $query->where(function ($query) use ($filter) {
                    $query->where('title', 'like', "%{$filter}%")
                        ->orWhere('slug', 'like', "%{$filter}%");
                });
            }

            $entries = $query->orderBy('updated_at', 'desc')
                ->offset($offset)
                ->limit($limit)
                ->get();

            $entriesData = [];
            foreach ($entries as $entry) {
                $entryData = [
                    'id' => $entry->id(),
                    'title' => $entry->get('title'),
                    'slug' => $entry->slug(),
                    'url' => $entry->url(),
                    'published' => $entry->published(),
                    'status' => $entry->status(),
                    'collection' => $entry->collection()->handle(),
                    'updated_at' => $entry->lastModified()?->toISOString(),
                ];

                if ($includeData) {
                    $entryData['data'] = $entry->data()->all();
                }

                $entriesData[] = $entryData;
            }

            return [
                'entries' => $entriesData,
                'count' => count($entriesData),
                'collection' => [
                    'handle' => $collection->handle(),
                    'title' => $collection->title(),
                ],
                'pagination' => [
                    'offset' => $offset,
                    'limit' => $limit,
                    'has_more' => count($entriesData) === $limit,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not list entries: ' . $e->getMessage())->toArray();
        }
    }
}
