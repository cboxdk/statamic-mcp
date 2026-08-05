<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ValidatesContentRecords;
use Cboxdk\StatamicMcp\Mcp\Validation\Finding;
use Cboxdk\StatamicMcp\Mcp\Validation\FindingType;
use Cboxdk\StatamicMcp\Mcp\Validation\RecordRef;
use Cboxdk\StatamicMcp\Mcp\Validation\RecordType;
use Cboxdk\StatamicMcp\Mcp\Validation\Severity;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Statamic\Contracts\Entries\QueryBuilder as EntryQueryBuilder;
use Statamic\Contracts\Globals\Variables;
use Statamic\Contracts\Structures\Nav as NavContract;
use Statamic\Contracts\Structures\Tree;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Stache\Query\TermQueryBuilder;

#[Name('statamic-content-facade')]
#[Title('Statamic Content Analysis')]
#[Description('High-level content analysis workflows spanning all content types. Workflows: content_audit reports content volume and coverage gaps; content_validate checks stored content against its blueprints and reports schema drift; cross_reference analyzes relationships and dependencies between content types.')]
class ContentFacadeRouter extends BaseRouter
{
    use ValidatesContentRecords;

    protected function getDomain(): string
    {
        return 'content-facade';
    }

    public function getActions(): array
    {
        return [
            'content_audit' => 'Scan all content for issues across collections, taxonomies, and globals',
            'content_validate' => 'Validate stored content against its blueprints and report schema drift',
            'cross_reference' => 'Analyze relationships and dependencies between content types',
        ];
    }

    public function getTypes(): array
    {
        return [
            'ContentAudit' => 'Result of content audit workflow',
            'ContentValidation' => 'Result of content validation sweep',
            'CrossReference' => 'Result of cross-reference analysis',
        ];
    }

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        return array_merge(parent::defineSchema($schema), [
            'filters' => JsonSchema::object()
                ->description('Optional filter conditions to narrow the workflow scope'),
            'scope' => JsonSchema::string()
                ->description('content_validate: which record types to sweep. Defaults to all.')
                ->enum(['all', ...RecordType::scopes()]),
            'collection' => JsonSchema::string()
                ->description('content_validate: restrict the entry sweep to one collection handle'),
            'taxonomy' => JsonSchema::string()
                ->description('content_validate: restrict the term sweep to one taxonomy handle'),
            'limit' => JsonSchema::integer()
                ->description('content_validate: how many records to scan in this call (default 100, max 500)'),
            'offset' => JsonSchema::integer()
                ->description('content_validate: record offset, for paging through a large site'),
            'max_findings' => JsonSchema::integer()
                ->description('content_validate: cap on findings returned per call (default 200, max 2000). Counts stay accurate when findings are truncated.'),
            'severity' => JsonSchema::string()
                ->description('content_validate: only return findings at this severity')
                ->enum(['error', 'warning']),
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
            'content_validate' => $this->executeContentValidate($arguments),
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

    /**
     * Validate stored content against its blueprints.
     *
     * Writes made through this addon are already validated on the way in. This
     * sweep is for everything else — git merges, hand-edited YAML, and blueprints
     * that changed after the content was written.
     *
     * Records are walked in a fixed order (entries, terms, globals, navigations)
     * and `offset`/`limit` address that combined stream, so paging through a
     * large site is a matter of repeating the call with a rising offset.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function executeContentValidate(array $arguments): array
    {
        $scope = $this->getStringArgument($arguments, 'scope', 'all');

        if ($scope !== 'all' && RecordType::fromScope($scope) === null) {
            return $this->createErrorResponse(
                "Unknown scope: {$scope}. Valid scopes are: all, " . implode(', ', RecordType::scopes())
            )->toArray();
        }

        $severityArgument = $this->getStringArgument($arguments, 'severity');
        $severity = $severityArgument === '' ? null : Severity::tryFrom($severityArgument);

        if ($severityArgument !== '' && $severity === null) {
            return $this->createErrorResponse(
                "Unknown severity: {$severityArgument}. Valid severities are: error, warning"
            )->toArray();
        }

        $collection = $this->getStringArgument($arguments, 'collection');
        $taxonomy = $this->getStringArgument($arguments, 'taxonomy');

        ['limit' => $limit, 'offset' => $offset] = $this->getPaginationArgs($arguments, 100, 500);
        $maxFindings = $this->getIntegerArgument($arguments, 'max_findings', 200, 1, 2000);

        /** @var list<RecordType> $recordTypes */
        $recordTypes = $scope === 'all'
            ? RecordType::cases()
            : array_filter([RecordType::fromScope($scope)]);

        try {
            /** @var list<Finding> $findings */
            $findings = [];
            $scanned = 0;
            $recordsWithIssues = 0;
            $segmentStart = 0;

            foreach ($recordTypes as $recordType) {
                $total = $this->validationUnitCount($recordType, $collection, $taxonomy);
                $window = $this->segmentWindow($segmentStart, $total, $offset, $limit);
                $segmentStart += $total;

                if ($window === null) {
                    continue;
                }

                foreach ($this->validationUnits($recordType, $collection, $taxonomy, $window[0], $window[1]) as $unit) {
                    $scanned++;
                    $unitFindings = $unit();

                    if ($unitFindings !== []) {
                        $recordsWithIssues++;
                        $findings = [...$findings, ...$unitFindings];
                    }
                }
            }

            if ($severity !== null) {
                $findings = array_values(array_filter(
                    $findings,
                    fn (Finding $finding): bool => $finding->severity() === $severity
                ));
            }

            $totalFindings = count($findings);

            // Findings become arrays only here, at the MCP response boundary.
            $returned = array_map(
                fn (Finding $finding): array => $finding->toArray(),
                array_slice($findings, 0, $maxFindings)
            );

            return [
                'workflow' => 'content_validate',
                'validated_at' => now()->toISOString(),
                'scope' => [
                    'record_types' => array_map(fn (RecordType $type): string => $type->scope(), $recordTypes),
                    'collection' => $collection !== '' ? $collection : null,
                    'taxonomy' => $taxonomy !== '' ? $taxonomy : null,
                    'severity' => $severity?->value,
                ],
                'summary' => [
                    'records_scanned' => $scanned,
                    'records_with_issues' => $recordsWithIssues,
                    'findings' => $totalFindings,
                    'by_severity' => $this->tally($findings, fn (Finding $f): string => $f->severity()->value),
                    'by_type' => $this->tally($findings, fn (Finding $f): string => $f->type->value),
                ],
                'findings' => $returned,
                'findings_truncated' => $totalFindings > count($returned),
                'pagination' => $this->buildPaginationMeta($segmentStart, $limit, $offset),
                'completed' => true,
                'message' => $totalFindings === 0
                    ? "Validated {$scanned} record(s); no problems found."
                    : "Validated {$scanned} record(s); found {$totalFindings} problem(s) in {$recordsWithIssues} record(s).",
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Content validation workflow failed: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * How many records a scope holds, so the caller can map the requested window
     * onto it without loading anything.
     */
    private function validationUnitCount(RecordType $recordType, string $collection, string $taxonomy): int
    {
        return match ($recordType) {
            RecordType::Entry => $this->entryQuery($collection)->count(),
            RecordType::Term => $this->termQuery($taxonomy)->count(),
            // Globals and navigations are bounded by configuration rather than
            // content volume, so materializing them to count is cheap.
            RecordType::Global => count($this->globalValidationUnits()),
            RecordType::Navigation => count($this->navigationValidationUnits()),
        };
    }

    /**
     * Build the records to validate for one scope's slice of the window.
     *
     * Each unit is a closure returning that record's findings, so nothing is
     * validated until the caller actually walks it.
     *
     * @return list<callable(): list<Finding>>
     */
    private function validationUnits(RecordType $recordType, string $collection, string $taxonomy, int $offset, int $limit): array
    {
        return match ($recordType) {
            RecordType::Entry => $this->entryValidationUnits($collection, $offset, $limit),
            RecordType::Term => $this->termValidationUnits($taxonomy, $offset, $limit),
            RecordType::Global => array_slice($this->globalValidationUnits(), $offset, $limit),
            RecordType::Navigation => array_slice($this->navigationValidationUnits(), $offset, $limit),
        };
    }

    private function entryQuery(string $collection): EntryQueryBuilder
    {
        $query = Entry::query();

        if ($collection !== '') {
            $query->where('collection', $collection);
        }

        return $query;
    }

    private function termQuery(string $taxonomy): TermQueryBuilder
    {
        $query = Term::query();

        if ($taxonomy !== '') {
            $query->where('taxonomy', $taxonomy);
        }

        return $query;
    }

    /**
     * @return list<callable(): list<Finding>>
     */
    private function entryValidationUnits(string $collection, int $offset, int $limit): array
    {
        $units = [];

        foreach ($this->entryQuery($collection)->offset($offset)->limit($limit)->get() as $entry) {
            /** @var \Statamic\Contracts\Entries\Entry $entry */
            $units[] = function () use ($entry): array {
                $blueprint = $entry->blueprint();

                if (! $blueprint instanceof Blueprint) {
                    return [];
                }

                return $this->validateRecord(
                    $blueprint->fields(),
                    // The slug lives outside data() but blueprints routinely mark
                    // it required, so fold it in or every entry looks like it is
                    // missing a required slug.
                    ['slug' => $entry->slug(), ...$entry->data()->all()],
                    new RecordRef(RecordType::Entry, (string) $entry->id(), $entry->locale())
                );
            };
        }

        return $units;
    }

    /**
     * @return list<callable(): list<Finding>>
     */
    private function termValidationUnits(string $taxonomy, int $offset, int $limit): array
    {
        $units = [];

        foreach ($this->termQuery($taxonomy)->offset($offset)->limit($limit)->get() as $term) {
            /** @var \Statamic\Contracts\Taxonomies\Term $term */
            $units[] = function () use ($term): array {
                $blueprint = $term->blueprint();

                if (! $blueprint instanceof Blueprint) {
                    return [];
                }

                return $this->validateRecord(
                    $blueprint->fields(),
                    ['slug' => $term->slug(), ...$term->data()->all()],
                    new RecordRef(RecordType::Term, (string) $term->id(), $term->locale())
                );
            };
        }

        return $units;
    }

    /**
     * One unit per global set localization — a set can be valid in one site and
     * broken in another.
     *
     * @return list<callable(): list<Finding>>
     */
    private function globalValidationUnits(): array
    {
        $units = [];

        /** @var \Illuminate\Support\Collection<int, \Statamic\Contracts\Globals\GlobalSet> $globalSets */
        $globalSets = GlobalSet::all();

        foreach ($globalSets as $globalSet) {
            /** @var \Statamic\Contracts\Globals\GlobalSet $globalSet */
            $blueprint = $globalSet->blueprint();

            if (! $blueprint instanceof Blueprint) {
                continue;
            }

            foreach ($globalSet->localizations() as $locale => $localization) {
                /** @var Variables $localization */
                $units[] = fn (): array => $this->validateRecord(
                    $blueprint->fields(),
                    $localization->data()->all(),
                    new RecordRef(RecordType::Global, $globalSet->handle(), (string) $locale)
                );
            }
        }

        return $units;
    }

    /**
     * One unit per navigation tree. Every menu item that links to an entry must
     * point at one that still exists — a dangling reference makes the item
     * silently vanish, which nothing on the front end flags.
     *
     * @return list<callable(): list<Finding>>
     */
    private function navigationValidationUnits(): array
    {
        $units = [];

        /** @var iterable<NavContract> $navs */
        $navs = Nav::all();

        foreach ($navs as $nav) {
            foreach ($nav->trees() as $locale => $tree) {
                /** @var Tree $tree */
                $units[] = function () use ($nav, $tree, $locale): array {
                    $findings = [];

                    foreach ($tree->flattenedPages() as $page) {
                        $reference = $page->reference();

                        if ($reference === null || $page->referenceExists()) {
                            continue;
                        }

                        $findings[] = new Finding(
                            FindingType::DanglingReference,
                            new RecordRef(RecordType::Navigation, $nav->handle(), (string) $locale),
                            "Menu item links to entry '{$reference}', which no longer exists.",
                        );
                    }

                    return $findings;
                };
            }
        }

        return $units;
    }

    /**
     * Map the requested [offset, offset + limit) window onto one segment of the
     * combined record stream.
     *
     * @return array{0: int, 1: int}|null Local offset and limit, or null when the segment falls outside the window
     */
    private function segmentWindow(int $segmentStart, int $segmentTotal, int $offset, int $limit): ?array
    {
        $from = max($offset, $segmentStart);
        $to = min($offset + $limit, $segmentStart + $segmentTotal);

        if ($from >= $to) {
            return null;
        }

        return [$from - $segmentStart, $to - $from];
    }

    /**
     * Count findings by some string facet, for the summary block.
     *
     * @param  list<Finding>  $findings
     * @param  callable(Finding): string  $facet
     *
     * @return array<string, int>
     */
    private function tally(array $findings, callable $facet): array
    {
        $counts = [];

        foreach ($findings as $finding) {
            $key = $facet($finding);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }
}
