<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;

#[Name('statamic-content-facade')]
#[Description('High-level content analysis workflows spanning all content types. Workflows: content_audit scans for issues across collections/taxonomies/globals; cross_reference analyzes relationships and dependencies between content types.')]
class ContentFacadeRouter extends BaseRouter
{
    protected function getDomain(): string
    {
        return 'content-facade';
    }

    public function getActions(): array
    {
        return [
            'content_audit' => 'Scan all content for issues across collections, taxonomies, and globals',
            'cross_reference' => 'Analyze relationships and dependencies between content types',
        ];
    }

    public function getTypes(): array
    {
        return [
            'ContentAudit' => 'Result of content audit workflow',
            'CrossReference' => 'Result of cross-reference analysis',
        ];
    }

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        return array_merge(parent::defineSchema($schema), [
            'filters' => JsonSchema::object()
                ->description('Optional filter conditions to narrow the workflow scope'),
        ]);
    }

    /**
     * Route workflows to appropriate handlers with security checks.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function executeAction(array $arguments): array
    {
        $action = is_string($arguments['action'] ?? null) ? $arguments['action'] : '';

        return match ($action) {
            'content_audit' => $this->executeContentAudit($arguments),
            'cross_reference' => $this->executeCrossReference($arguments),
            default => $this->createErrorResponse("Unknown action: {$action}")->toArray(),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string>
     */
    protected function getRequiredPermissions(string $action, array $arguments): array
    {
        // Content audit and cross-reference span ALL collections, taxonomies, and globals.
        // There is no single Statamic permission that grants cross-domain read access.
        // Only super admins (who bypass permission checks in checkWebPermissions) should
        // run these broad audit workflows.
        return ['super'];
    }

    /**
     * Execute content audit workflow.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function executeContentAudit(array $arguments): array
    {
        $results = [
            'workflow' => 'content_audit',
            'audit_timestamp' => now()->toISOString(),
            'summary' => [
                'total_entries' => 0,
                'total_terms' => 0,
                'total_globals' => 0,
                'issues_found' => 0,
                'quality_score' => 0,
            ],
            'details' => [
                'collections' => [],
                'taxonomies' => [],
                'globals' => [],
            ],
            'recommendations' => [],
        ];

        try {
            // Collect all collection metadata first
            /** @var iterable<\Statamic\Contracts\Entries\Collection> $collections */
            $collections = Collection::all();

            /** @var array<string, array{title: string, entry_count: int, published_count: int}> $collectionData */
            $collectionData = [];
            foreach ($collections as $collection) {
                $collectionData[$collection->handle()] = [
                    'title' => $collection->title(),
                    'entry_count' => 0,
                    'published_count' => 0,
                ];
            }

            // Single pass over all entries instead of per-collection queries
            $allEntries = Entry::query()->get();
            foreach ($allEntries as $entry) {
                /** @var \Statamic\Contracts\Entries\Entry $entry */
                $col = $entry->collectionHandle();
                if (isset($collectionData[$col])) {
                    $collectionData[$col]['entry_count']++;
                    if ($entry->published()) {
                        $collectionData[$col]['published_count']++;
                    }
                }
            }

            // Build collection details from aggregated data
            foreach ($collectionData as $handle => $data) {
                $results['summary']['total_entries'] += $data['entry_count'];
                $results['details']['collections'][] = [
                    'handle' => $handle,
                    'title' => $data['title'],
                    'entry_count' => $data['entry_count'],
                    'published_count' => $data['published_count'],
                ];
            }

            // Audit terms — single query over all terms
            /** @var iterable<\Statamic\Contracts\Taxonomies\Taxonomy> $taxonomies */
            $taxonomies = Taxonomy::all();

            /** @var array<string, array{title: string, term_count: int}> $taxonomyData */
            $taxonomyData = [];
            foreach ($taxonomies as $taxonomy) {
                $taxonomyData[$taxonomy->handle()] = [
                    'title' => $taxonomy->title(),
                    'term_count' => 0,
                ];
            }

            $allTerms = Term::query()->get();
            foreach ($allTerms as $term) {
                $tax = $term->taxonomyHandle();
                if (isset($taxonomyData[$tax])) {
                    $taxonomyData[$tax]['term_count']++;
                }
            }

            foreach ($taxonomyData as $handle => $data) {
                $results['summary']['total_terms'] += $data['term_count'];
                $results['details']['taxonomies'][] = [
                    'handle' => $handle,
                    'title' => $data['title'],
                    'term_count' => $data['term_count'],
                ];
            }

            // Audit globals
            /** @var \Illuminate\Support\Collection<int, \Statamic\Contracts\Globals\GlobalSet> $globalSets */
            $globalSets = GlobalSet::all();
            $results['summary']['total_globals'] = $globalSets->count();

            /** @var \Statamic\Sites\Site $defaultSite */
            $defaultSite = Site::default();
            foreach ($globalSets as $globalSet) {
                /** @var \Statamic\Contracts\Globals\GlobalSet $globalSet */
                $hasValues = $globalSet->in($defaultSite->handle())->data()->isNotEmpty();
                $results['details']['globals'][] = [
                    'handle' => $globalSet->handle(),
                    'title' => $globalSet->title(),
                    'has_values' => $hasValues,
                ];
            }

            // Quality checks — detect real issues
            $issues = 0;

            foreach ($results['details']['collections'] as $col) {
                if ($col['entry_count'] === 0) {
                    $issues++;
                    $results['recommendations'][] = "Collection '{$col['handle']}' has no entries";
                } elseif ($col['published_count'] === 0) {
                    $issues++;
                    $results['recommendations'][] = "Collection '{$col['handle']}' has entries but none are published";
                }
            }

            foreach ($results['details']['taxonomies'] as $tax) {
                if ($tax['term_count'] === 0) {
                    $issues++;
                    $results['recommendations'][] = "Taxonomy '{$tax['handle']}' has no terms";
                }
            }

            foreach ($results['details']['globals'] as $global) {
                if (! $global['has_values']) {
                    $issues++;
                    $results['recommendations'][] = "Global set '{$global['handle']}' has no values set";
                }
            }

            $results['summary']['issues_found'] = $issues;

            // Calculate quality score
            $totalContent = $results['summary']['total_entries'] + $results['summary']['total_terms'] + $results['summary']['total_globals'];
            $results['summary']['quality_score'] = $totalContent > 0 ? max(0, round(100 - ($issues / max($totalContent, 1) * 100))) : 100;

            $results['completed'] = true;
            $results['message'] = 'Content audit completed successfully';

            return $results;

        } catch (\Exception $e) {
            return $this->createErrorResponse("Content audit workflow failed: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Execute cross reference workflow.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function executeCrossReference(array $arguments): array
    {
        $results = [
            'workflow' => 'cross_reference',
            'analysis_timestamp' => now()->toISOString(),
            'relationships' => [
                'entry_to_term' => [],
                'entry_to_global' => [],
                'orphaned_content' => [],
            ],
            'statistics' => [
                'total_relationships' => 0,
                'orphaned_entries' => 0,
                'orphaned_terms' => 0,
            ],
        ];

        try {
            // Build a set of taxonomy field handles by inspecting collection blueprints
            /** @var array<string, list<string>> $collectionTaxonomyFields */
            $collectionTaxonomyFields = [];

            /** @var iterable<\Statamic\Contracts\Entries\Collection> $collections */
            $collections = Collection::all();
            foreach ($collections as $collection) {
                /** @var \Statamic\Contracts\Entries\Collection $collection */
                $taxonomyFieldHandles = [];

                // Get taxonomy fields from blueprints (accurate detection, not heuristic)
                /** @var \Illuminate\Support\Collection<int, Blueprint> $entryBlueprints */
                $entryBlueprints = $collection->entryBlueprints();
                foreach ($entryBlueprints as $bp) {
                    foreach ($bp->fields()->all() as $field) {
                        /** @var Field $field */
                        if (in_array($field->type(), ['terms', 'taxonomies'], true)) {
                            $taxonomyFieldHandles[] = $field->handle();
                        }
                    }
                }

                $collectionTaxonomyFields[$collection->handle()] = array_unique($taxonomyFieldHandles);
            }

            // Analyze entry-term relationships and collect referenced term slugs in a single pass
            /** @var array<string, bool> $referencedTermSlugs */
            $referencedTermSlugs = [];

            foreach ($collections as $collection) {
                /** @var \Statamic\Contracts\Entries\Collection $collection */
                $taxFields = $collectionTaxonomyFields[$collection->handle()] ?? [];
                $page = 1;
                $perPage = 100;

                do {
                    $entries = Entry::query()
                        ->where('collection', $collection->handle())
                        ->limit($perPage)
                        ->offset(($page - 1) * $perPage)
                        ->get();

                    foreach ($entries as $entry) {
                        $entryData = $entry->data();
                        $termReferences = 0;

                        // Only check actual taxonomy fields from the blueprint
                        foreach ($taxFields as $fieldHandle) {
                            $value = $entryData->get($fieldHandle);
                            if (is_array($value)) {
                                $termReferences += count($value);
                                foreach ($value as $slug) {
                                    if (is_string($slug) && $slug !== '') {
                                        $referencedTermSlugs[$slug] = true;
                                    }
                                }
                            } elseif (is_string($value) && $value !== '') {
                                $termReferences++;
                                $referencedTermSlugs[$value] = true;
                            }
                        }

                        if ($termReferences === 0) {
                            $results['statistics']['orphaned_entries']++;
                        } else {
                            $results['statistics']['total_relationships'] += $termReferences;
                        }
                    }

                    $page++;
                } while ($entries->count() === $perPage);
            }

            // Now check all terms against the collected references (no per-term query)
            /** @var iterable<\Statamic\Contracts\Taxonomies\Taxonomy> $taxonomies */
            $taxonomies = Taxonomy::all();
            foreach ($taxonomies as $taxonomy) {
                /** @var \Statamic\Contracts\Taxonomies\Taxonomy $taxonomy */
                $terms = Term::query()->where('taxonomy', $taxonomy->handle())->get();

                foreach ($terms as $term) {
                    $termSlug = $term->slug();
                    $termId = $term->id();

                    // A term is orphaned if neither its slug nor its full ID is referenced
                    $isReferenced = isset($referencedTermSlugs[$termSlug])
                        || (is_string($termId) && isset($referencedTermSlugs[$termId]));

                    if (! $isReferenced) {
                        $results['statistics']['orphaned_terms']++;
                        $results['relationships']['orphaned_content'][] = [
                            'type' => 'term',
                            'id' => $termId,
                            'title' => $term->get('title', $term->slug()),
                            'taxonomy' => $term->taxonomyHandle(),
                        ];
                    }
                }
            }

            $results['completed'] = true;
            $results['message'] = 'Cross reference analysis completed successfully';

            return $results;

        } catch (\Exception $e) {
            return $this->createErrorResponse("Cross reference workflow failed: {$e->getMessage()}")->toArray();
        }
    }
}
