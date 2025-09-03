<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Taxonomies;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

#[Title('Analyze Statamic Taxonomy Usage')]
#[IsReadOnly]
class AnalyzeTaxonomyTool extends BaseStatamicTool
{
    protected function getToolName(): string
    {
        return 'statamic.taxonomies.analyze';
    }

    protected function getToolDescription(): string
    {
        return 'Analyze taxonomy usage, term counts, and relationships';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('handle')
            ->description('Taxonomy handle (optional - analyzes all if not specified)')
            ->optional()
            ->boolean('include_term_usage')
            ->description('Include detailed term usage counts')
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
        $handle = $arguments['handle'] ?? null;
        $includeTermUsage = $arguments['include_term_usage'] ?? false;

        try {
            $taxonomies = $handle ? [Taxonomy::findByHandle($handle)] : Taxonomy::all()->all();
            $taxonomies = array_filter($taxonomies); // Remove null values

            if (empty($taxonomies)) {
                return $this->createErrorResponse($handle ? "Taxonomy '{$handle}' not found" : 'No taxonomies found')->toArray();
            }

            $analysis = [];

            foreach ($taxonomies as $taxonomy) {
                $termCount = Term::whereTaxonomy($taxonomy->handle())->count();
                $collections = $taxonomy->collections()?->map(fn ($item) => $item->handle())->all() ?? [];

                $taxonomyAnalysis = [
                    'handle' => $taxonomy->handle(),
                    'title' => $taxonomy->title(),
                    'term_count' => $termCount,
                    'collections' => $collections,
                    'collection_count' => count($collections),
                    'sites' => $taxonomy->sites(),
                    'blueprint' => $taxonomy->blueprint()?->handle(),
                ];

                if ($includeTermUsage && $termCount > 0) {
                    $terms = Term::whereTaxonomy($taxonomy->handle())->all();
                    $termUsage = [];

                    foreach ($terms as $term) {
                        $usageCount = $this->getTermUsageCount($term);
                        $termUsage[] = [
                            'id' => $term->id(),
                            'slug' => $term->slug(),
                            'title' => $term->title(),
                            'usage_count' => $usageCount,
                        ];
                    }

                    // Sort by usage count descending
                    usort($termUsage, fn ($a, $b) => $b['usage_count'] <=> $a['usage_count']);

                    $taxonomyAnalysis['terms'] = $termUsage;
                    $taxonomyAnalysis['total_usage'] = array_sum(array_column($termUsage, 'usage_count'));
                    $taxonomyAnalysis['unused_terms'] = count(array_filter($termUsage, fn ($term) => $term['usage_count'] === 0));
                }

                $analysis[] = $taxonomyAnalysis;
            }

            return [
                'analysis' => $analysis,
                'summary' => [
                    'total_taxonomies' => count($analysis),
                    'total_terms' => array_sum(array_column($analysis, 'term_count')),
                    // @phpstan-ignore-next-line
                    'average_terms_per_taxonomy' => count($analysis) !== 0 ? round(array_sum(array_column($analysis, 'term_count')) / count($analysis), 2) : 0,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not analyze taxonomy: ' . $e->getMessage())->toArray();
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
