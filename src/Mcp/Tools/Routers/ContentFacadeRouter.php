<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ValidatesContentRecords;
use Cboxdk\StatamicMcp\Mcp\Tools\RouterAction;
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
#[Description('Statamic content analysis across collections, taxonomies and globals: volume and coverage, stored content that no longer matches its blueprint, and references to records that no longer exist.')]
class ContentFacadeRouter extends BaseRouter
{
    use ValidatesContentRecords;

    protected function getDomain(): string
    {
        return 'content-facade';
    }

    protected function actions(): array
    {
        return [
            RouterAction::read('content_audit', 'Report content volume and coverage gaps across collections, taxonomies and globals', optional: []),
            RouterAction::read('content_validate', 'Check stored content against its blueprints and report drift', optional: ['scope', 'collection', 'taxonomy', 'severity', 'limit', 'cursor', 'max_findings']),
            RouterAction::read('cross_reference', 'Map relationships between content types and find references to records that no longer exist', optional: []),
        ];
    }

    public function getTypes(): array
    {
        return [];
    }

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        return array_merge(parent::defineSchema($schema), [
            'scope' => JsonSchema::string()
                ->description('which record types to sweep. Defaults to all.')
                ->enum(['all', ...RecordType::scopes()]),
            'collection' => JsonSchema::string()
                ->description('restrict the entry sweep to one collection handle'),
            'taxonomy' => JsonSchema::string()
                ->description('restrict the term sweep to one taxonomy handle'),
            'limit' => JsonSchema::integer()
                ->description('how many records to scan in this call (default 100, max 500)'),
            'cursor' => JsonSchema::string()
                ->description('next_cursor from the previous scan. Keep the other arguments unchanged. Omit to start again.'),
            'max_findings' => JsonSchema::integer()
                ->description('cap on findings returned per call (default 200, max 2000). Counts stay accurate when findings are truncated.'),
            'severity' => JsonSchema::string()
                ->description('only return findings at this severity')
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
        // Collect all collection metadata first
        /** @var iterable<\Statamic\Contracts\Entries\Collection> $collections */
        $collections = Collection::all();
        /** @var array<string, array{title: string, entry_count: int, published_count: int}> $collectionData */
        $collectionData = [];
        foreach ($collections as $collection) {
            if (! in_array((string) $collection->handle(), $this->readableCollectionHandles(), true)) {
                continue;
            }
            $collectionData[$collection->handle()] = [
                'title' => $collection->title(),
                'entry_count' => Entry::query()->where('collection', $collection->handle())->count(),
                'published_count' => Entry::query()->where('collection', $collection->handle())->where('published', true)->count(),
            ];
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
            if (! in_array((string) $taxonomy->handle(), $this->readableTaxonomyHandles(), true)) {
                continue;
            }
            $taxonomyData[$taxonomy->handle()] = [
                'title' => $taxonomy->title(),
                'term_count' => Term::query()->where('taxonomy', $taxonomy->handle())->count(),
            ];
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
        /** @var \Statamic\Sites\Site $defaultSite */
        $defaultSite = Site::default();
        foreach ($globalSets as $globalSet) {
            /** @var \Statamic\Contracts\Globals\GlobalSet $globalSet */
            if (! $this->globalIsReadable((string) $globalSet->handle())) {
                continue;
            }
            $results['summary']['total_globals']++;
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
        // Refuse rather than report orphaned terms from an incomplete entry scan.
        // Also enforce the bound while scanning, because content can grow meanwhile.
        $budget = 5000;
        $records = Entry::query()->whereIn('collection', $this->readableCollectionHandles())->count()
            + Term::query()->whereIn('taxonomy', $this->readableTaxonomyHandles())->count();
        if ($records > $budget) {
            return $this->createErrorResponse('Cross reference is limited to 5000 readable records. Use paginated content_validate for larger sites.', code: ErrorCode::TooLarge)->toArray();
        }
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
        // Build a set of taxonomy field handles by inspecting collection blueprints
        /** @var array<string, list<string>> $collectionTaxonomyFields */
        $collectionTaxonomyFields = [];
        /** @var iterable<\Statamic\Contracts\Entries\Collection> $collections */
        $collections = Collection::all();
        foreach ($collections as $collection) {
            if (! in_array((string) $collection->handle(), $this->readableCollectionHandles(), true)) {
                continue;
            }
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
            if (! in_array((string) $collection->handle(), $this->readableCollectionHandles(), true)) {
                continue;
            }
            /** @var \Statamic\Contracts\Entries\Collection $collection */
            $taxFields = $collectionTaxonomyFields[$collection->handle()] ?? [];
            $page = 1;
            $perPage = 100;

            do {
                $entries = Entry::query()
                    ->where('collection', $collection->handle())
                    ->orderBy('id')
                    ->limit($perPage)
                    ->offset(($page - 1) * $perPage)
                    ->get();

                foreach ($entries as $entry) {
                    if (--$budget < 0) {
                        return $this->createErrorResponse('Cross reference exceeded 5000 records while scanning. Use paginated content_validate.', code: ErrorCode::TooLarge)->toArray();
                    }
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
            if (! in_array((string) $taxonomy->handle(), $this->readableTaxonomyHandles(), true)) {
                continue;
            }
            $terms = Term::query()->where('taxonomy', $taxonomy->handle())->orderBy('id')->limit($budget + 1)->get();

            foreach ($terms as $term) {
                if (--$budget < 0) {
                    return $this->createErrorResponse('Cross reference exceeded 5000 records while scanning. Use paginated content_validate.', code: ErrorCode::TooLarge)->toArray();
                }
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
    }

    /**
     * Validate stored content against its blueprints.
     *
     * Writes made through this addon are already validated on the way in. This
     * sweep is for everything else — git merges, hand-edited YAML, and blueprints
     * that changed after the content was written.
     *
     * Records are walked in a fixed order (entries, terms, globals, navigations)
     * and cursor/limit address that combined stream, not the list of findings.
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
            'total' => $segmentStart,
            'next_cursor' => $scanned === 0 ? null : $this->nextCursor($segmentStart, $offset + $scanned, $arguments),
            'completed' => true,
            'message' => $totalFindings === 0
                ? "Validated {$scanned} record(s); no problems found."
                : "Validated {$scanned} record(s); found {$totalFindings} problem(s) in {$recordsWithIssues} record(s).",
        ];
    }

    /**
     * How many records a scope holds, so the caller can map the requested window
     * onto it without loading anything.
     */
    private function validationUnitCount(RecordType $recordType, string $collection, string $taxonomy): int
    {
        return match ($recordType) {
            RecordType::Entry => $this->entryQuery($collection)?->count() ?? 0,
            RecordType::Term => $this->termQuery($taxonomy)?->count() ?? 0,
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

    /**
     * The entries a sweep may read, or null when it may read none.
     *
     * A named collection that does not exist or that the policy denies, and an
     * "all collections" sweep with nothing permitted, resolve to no query at
     * all: the Stache has no store for a handle it does not know, so querying
     * one fails instead of answering empty.
     */
    private function entryQuery(string $collection): ?EntryQueryBuilder
    {
        $allowed = $this->readableCollectionHandles();
        $handles = $collection !== '' ? array_intersect([$collection], $allowed) : $allowed;

        return $handles === [] ? null : Entry::query()->whereIn('collection', array_values($handles));
    }

    /**
     * The terms a sweep may read, or null when it may read none (see entryQuery()).
     */
    private function termQuery(string $taxonomy): ?TermQueryBuilder
    {
        $allowed = $this->readableTaxonomyHandles();
        $handles = $taxonomy !== '' ? array_intersect([$taxonomy], $allowed) : $allowed;

        return $handles === [] ? null : Term::query()->whereIn('taxonomy', array_values($handles));
    }

    /**
     * @return list<callable(): list<Finding>>
     */
    private function entryValidationUnits(string $collection, int $offset, int $limit): array
    {
        $units = [];
        $query = $this->entryQuery($collection);

        foreach ($query?->orderBy('id')->offset($offset)->limit($limit)->get() ?? [] as $entry) {
            /** @var \Statamic\Contracts\Entries\Entry $entry */
            $units[] = function () use ($entry): array {
                $blueprint = $entry->blueprint();

                if (! $blueprint instanceof Blueprint) {
                    return [];
                }

                return $this->validateRecord(
                    $blueprint->fields(),
                    $this->entryValidationData($entry),
                    new RecordRef(RecordType::Entry, (string) $entry->id(), $entry->locale())
                );
            };
        }

        return $units;
    }

    /**
     * The data an entry should be validated against.
     *
     * Not simply data(): a couple of an entry's fields live outside it while
     * still being declared — and required — in the blueprint, so validating
     * data() alone reports them missing on every single row.
     *
     * The slug is one. The date is the other: Statamic injects a required date
     * field into a dated collection's blueprint at runtime, but the value is a
     * filename prefix on the file driver and a column on the Eloquent one, so
     * it never appears in data() and every dated entry failed (#57).
     *
     * Read the date off the entry rather than the collection — Collection has
     * no time-related accessor at all, and reaching for one silently aborted
     * the whole sweep on its first dated entry.
     *
     * @return array<string, mixed>
     */
    private function entryValidationData(\Statamic\Contracts\Entries\Entry $entry): array
    {
        $data = ['slug' => $entry->slug(), ...$entry->data()->all()];

        if ($entry->hasDate()) {
            $date = $entry->date();

            if ($date !== null) {
                // The Carbon instance, not a formatted string. Statamic's own
                // date rule returns early for a Carbon — it treats one as
                // already processed — so there is no format to guess at and no
                // time_enabled config to second-guess.
                $data['date'] = $date;
            }
        }

        return $data;
    }

    /**
     * @return list<callable(): list<Finding>>
     */
    private function termValidationUnits(string $taxonomy, int $offset, int $limit): array
    {
        $units = [];
        $query = $this->termQuery($taxonomy);

        foreach ($query?->orderBy('id')->offset($offset)->limit($limit)->get() ?? [] as $term) {
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
        $globalSets = GlobalSet::all()->sortBy->handle();

        foreach ($globalSets as $globalSet) {
            /** @var \Statamic\Contracts\Globals\GlobalSet $globalSet */
            $blueprint = $globalSet->blueprint();

            if (! $blueprint instanceof Blueprint) {
                continue;
            }

            foreach ($globalSet->localizations()->sortKeys() as $locale => $localization) {
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
        $navs = Nav::all()->sortBy->handle();

        foreach ($navs as $nav) {
            foreach ($nav->trees()->sortKeys() as $locale => $tree) {
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

    /**
     * Collection handles the resource policy permits reading. The facade sweeps
     * content that belongs to the entries/terms/globals domains, so it must
     * honour those domains' allowlists even though the facade is super-only —
     * the resource policy is a site-wide restriction that binds super admins too.
     *
     * @return list<string>
     */
    private function readableCollectionHandles(): array
    {
        /** @var ResourcePolicy $policy */
        $policy = app(ResourcePolicy::class);

        return array_values(Collection::handles()
            ->filter(fn (mixed $h): bool => is_string($h) && $policy->canAccess('entries', $h, 'read'))
            ->all());
    }

    /**
     * @return list<string>
     */
    private function readableTaxonomyHandles(): array
    {
        /** @var ResourcePolicy $policy */
        $policy = app(ResourcePolicy::class);

        return array_values(Taxonomy::handles()
            ->filter(fn (mixed $h): bool => is_string($h) && $policy->canAccess('terms', $h, 'read'))
            ->all());
    }

    private function globalIsReadable(string $handle): bool
    {
        /** @var ResourcePolicy $policy */
        $policy = app(ResourcePolicy::class);

        return $policy->canAccess('globals', $handle, 'read');
    }
}
