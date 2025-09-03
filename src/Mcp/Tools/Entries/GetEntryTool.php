<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Entries;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Entry;

#[Title('Get Statamic Entry')]
#[IsReadOnly]
class GetEntryTool extends BaseStatamicTool
{
    protected function getToolName(): string
    {
        return 'statamic.entries.get';
    }

    protected function getToolDescription(): string
    {
        return 'Get detailed information about a specific entry';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('id')
            ->description('Entry ID')
            ->required()
            ->boolean('include_blueprint')
            ->description('Include blueprint information')
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
        $includeBlueprint = $arguments['include_blueprint'] ?? false;

        $entry = Entry::find($id);
        if (! $entry) {
            return $this->createErrorResponse("Entry '{$id}' not found")->toArray();
        }

        try {
            $entryData = [
                'id' => $entry->id(),
                'title' => $entry->get('title'),
                'slug' => $entry->slug(),
                'url' => $entry->url(),
                'published' => $entry->published(),
                'status' => $entry->status(),
                'collection' => [
                    'handle' => $entry->collection()->handle(),
                    'title' => $entry->collection()->title(),
                ],
                'site' => $entry->locale(),
                'data' => $entry->data()->all(),
                'created_at' => $entry->date()?->toISOString(),
                'updated_at' => $entry->lastModified()?->toISOString(),
            ];

            if ($includeBlueprint) {
                $blueprint = $entry->blueprint();
                $entryData['blueprint'] = [
                    'handle' => $blueprint->handle(),
                    'title' => $blueprint->title(),
                    'fields' => $blueprint->fields()->all()->map(function ($field) {
                        return [
                            'handle' => $field->handle(),
                            'type' => $field->type(),
                            'display' => $field->display(),
                            'required' => $field->isRequired(),
                        ];
                    })->toArray(),
                ];
            }

            return [
                'entry' => $entryData,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not retrieve entry: ' . $e->getMessage())->toArray();
        }
    }
}
