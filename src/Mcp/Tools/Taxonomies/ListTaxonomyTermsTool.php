<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Taxonomies;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

#[Title('List Taxonomy Terms')]
#[IsReadOnly]
class ListTaxonomyTermsTool extends BaseStatamicTool
{
    use \Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.taxonomies.terms';
    }

    protected function getToolDescription(): string
    {
        return 'List terms within a specific taxonomy with optional filtering and data';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema = $this->addLimitSchema($schema);

        return $schema->string('taxonomy')
            ->description('Taxonomy handle')
            ->required()
            ->boolean('include_data')
            ->description('Include term data/content')
            ->optional()
            ->boolean('include_usage_counts')
            ->description('Include usage counts for terms')
            ->optional()
            ->string('filter')
            ->description('Filter terms by title or slug')
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
        $taxonomyHandle = $arguments['taxonomy'];
        $includeData = $arguments['include_data'] ?? false;
        $includeUsageCounts = $arguments['include_usage_counts'] ?? false;
        $filter = $arguments['filter'] ?? null;
        $limit = $arguments['limit'] ?? null;

        try {
            $taxonomy = Taxonomy::findByHandle($taxonomyHandle);

            if (! $taxonomy) {
                return $this->createErrorResponse("Taxonomy '{$taxonomyHandle}' not found")->toArray();
            }

            $terms = Term::whereTaxonomy($taxonomyHandle);

            if ($limit) {
                $terms = $terms->take($limit);
            }

            $termData = [];
            foreach ($terms->all() as $term) {
                if ($filter && ! str_contains($term->title(), $filter) && ! str_contains($term->slug(), $filter)) {
                    continue;
                }

                $termInfo = [
                    'id' => $term->id(),
                    'slug' => $term->slug(),
                    'title' => $term->title(),
                    'uri' => $term->uri(),
                ];

                if ($includeUsageCounts) {
                    $termInfo['usage_count'] = $this->getTermUsageCount($term);
                }

                if ($includeData) {
                    $termInfo['data'] = $term->data();
                }

                $termData[] = $termInfo;
            }

            return [
                'taxonomy' => [
                    'handle' => $taxonomy->handle(),
                    'title' => $taxonomy->title(),
                ],
                'terms' => $termData,
                'count' => count($termData),
                'total_terms' => Term::whereTaxonomy($taxonomyHandle)->count(),
                'filtered' => $filter !== null,
                'limited' => $limit !== null,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not list terms: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Get term usage count.
     *
     * @param  mixed  $term
     */
    private function getTermUsageCount($term): int
    {
        try {
            // Count entries using this term across all collections
            $count = 0;
            $taxonomy = Taxonomy::findByHandle($term->taxonomyHandle());

            if ($taxonomy && $taxonomy->collections()) {
                foreach ($taxonomy->collections() as $collection) {
                    $entries = $collection->entries()
                        ->filter(function ($entry) use ($term) {
                            $taxonomyField = $entry->get($term->taxonomyHandle());
                            if (is_array($taxonomyField)) {
                                return in_array($term->slug(), $taxonomyField);
                            }

                            return $taxonomyField === $term->slug();
                        });

                    $count += $entries->count();
                }
            }

            return $count;
        } catch (\Exception $e) {
            return 0;
        }
    }
}
