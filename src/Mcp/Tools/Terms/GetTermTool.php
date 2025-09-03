<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Terms;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Term;

#[Title('Get Statamic Term')]
#[IsReadOnly]
class GetTermTool extends BaseStatamicTool
{
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.terms.get';
    }

    protected function getToolDescription(): string
    {
        return 'Get a specific taxonomy term with full details';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('id')
            ->description('Term ID or taxonomy::slug format')
            ->required()
            ->boolean('include_data')
            ->description('Include all term field data')
            ->optional()
            ->boolean('include_related')
            ->description('Include related entries')
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
        $id = $arguments['id'];
        $includeData = $arguments['include_data'] ?? true;
        $includeRelated = $arguments['include_related'] ?? false;

        try {
            $term = Term::find($id);
            if (! $term) {
                return $this->createErrorResponse("Term '{$id}' not found")->toArray();
            }

            $data = [
                'id' => $term->id(),
                'title' => $term->get('title'),
                'slug' => $term->slug(),
                'taxonomy' => $term->taxonomy()->handle(),
                'taxonomy_title' => $term->taxonomy()->title(),
                'published' => $term->published(),
                'uri' => $term->uri(),
                'url' => $term->url(),
                'created_at' => $term->date()?->toISOString(),
                'updated_at' => $term->lastModified()?->toISOString(),
            ];

            if ($includeData) {
                $data['data'] = $term->data()->toArray();
                $data['blueprint'] = $term->blueprint()->handle();
            }

            if ($includeRelated) {
                // Get entries that reference this term
                $relatedEntries = $term->queryEntries()->get()->map(function ($entry) {
                    return [
                        'id' => $entry->id(),
                        'title' => $entry->get('title'),
                        'collection' => $entry->collection()->handle(),
                        'url' => $entry->url(),
                        'published' => $entry->published(),
                    ];
                });

                $data['related_entries'] = $relatedEntries->toArray();
                $data['related_count'] = $relatedEntries->count();
            }

            return [
                'term' => $data,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not get term: ' . $e->getMessage())->toArray();
        }
    }
}
