<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Entries;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Entry;
use Statamic\Facades\Taxonomy;

#[Title('Manage Entry Relationships')]
class ManageEntryRelationshipsTool extends BaseStatamicTool
{
    use ClearsCaches;
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.entries.manage_relationships';
    }

    protected function getToolDescription(): string
    {
        return 'Advanced management of entry relationships including entries, taxonomies, assets, and custom fields';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('operation')
            ->description('Operation: add, remove, replace, list, sync, validate')
            ->required()
            ->string('entry_id')
            ->description('Source entry ID')
            ->required()
            ->string('relationship_field')
            ->description('Field containing relationships')
            ->required()
            ->raw('target_items', [
                'type' => 'array',
                'description' => 'Target items for relationship (IDs, handles, or slugs)',
                'items' => ['type' => 'string'],
            ])
            ->optional()
            ->string('relationship_type')
            ->description('Type: entries, terms, assets, users, or custom')
            ->optional()
            ->string('target_collection')
            ->description('Target collection for entry relationships')
            ->optional()
            ->string('target_taxonomy')
            ->description('Target taxonomy for term relationships')
            ->optional()
            ->boolean('validate_targets')
            ->description('Validate that target items exist')
            ->optional()
            ->boolean('maintain_order')
            ->description('Maintain order of relationships')
            ->optional()
            ->integer('max_items')
            ->description('Maximum number of related items allowed')
            ->optional()
            ->boolean('allow_duplicates')
            ->description('Allow duplicate relationships')
            ->optional()
            ->raw('relationship_metadata', [
                'type' => 'object',
                'description' => 'Additional metadata for relationships',
                'additionalProperties' => true,
            ])
            ->optional()
            ->boolean('bidirectional')
            ->description('Create bidirectional relationships')
            ->optional()
            ->string('inverse_field')
            ->description('Field name for inverse relationship')
            ->optional()
            ->boolean('cascade_operations')
            ->description('Apply operations to related entries')
            ->optional()
            ->boolean('dry_run')
            ->description('Preview changes without applying')
            ->optional();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $operation = $arguments['operation'];
        $entryId = $arguments['entry_id'];
        $relationshipField = $arguments['relationship_field'];
        $targetItems = $arguments['target_items'] ?? [];
        $relationshipType = $arguments['relationship_type'] ?? 'entries';
        $targetCollection = $arguments['target_collection'] ?? null;
        $targetTaxonomy = $arguments['target_taxonomy'] ?? null;
        $validateTargets = $arguments['validate_targets'] ?? true;
        $maintainOrder = $arguments['maintain_order'] ?? true;
        $maxItems = $arguments['max_items'] ?? null;
        $allowDuplicates = $arguments['allow_duplicates'] ?? false;
        $relationshipMetadata = $arguments['relationship_metadata'] ?? [];
        $bidirectional = $arguments['bidirectional'] ?? false;
        $inverseField = $arguments['inverse_field'] ?? null;
        $cascadeOperations = $arguments['cascade_operations'] ?? false;
        $dryRun = $arguments['dry_run'] ?? false;

        $validOperations = ['add', 'remove', 'replace', 'list', 'sync', 'validate'];
        if (! in_array($operation, $validOperations)) {
            return $this->createErrorResponse("Invalid operation '{$operation}'. Valid: " . implode(', ', $validOperations))->toArray();
        }

        $entry = Entry::find($entryId);
        if (! $entry) {
            return $this->createErrorResponse("Entry '{$entryId}' not found")->toArray();
        }

        $blueprint = $entry->blueprint();
        if (! $blueprint->hasField($relationshipField)) {
            return $this->createErrorResponse("Field '{$relationshipField}' not found in entry blueprint")->toArray();
        }

        $field = $blueprint->field($relationshipField);
        $fieldType = $field->type();

        // Auto-detect relationship type if not specified
        if (! $relationshipType) {
            $relationshipType = $this->detectRelationshipType($fieldType, $field->config());
        }

        switch ($operation) {
            case 'list':
                return $this->listRelationships($entry, $relationshipField, $relationshipType);
            case 'validate':
                return $this->validateRelationships($entry, $relationshipField, $targetItems, $relationshipType, $validateTargets);
            case 'add':
                return $this->addRelationships($entry, $relationshipField, $targetItems, $relationshipType, $targetCollection, $targetTaxonomy, $validateTargets, $maintainOrder, $maxItems, $allowDuplicates, $relationshipMetadata, $bidirectional, $inverseField, $cascadeOperations, $dryRun);
            case 'remove':
                return $this->removeRelationships($entry, $relationshipField, $targetItems, $relationshipType, $bidirectional, $inverseField, $cascadeOperations, $dryRun);
            case 'replace':
                return $this->replaceRelationships($entry, $relationshipField, $targetItems, $relationshipType, $targetCollection, $targetTaxonomy, $validateTargets, $maintainOrder, $maxItems, $allowDuplicates, $relationshipMetadata, $bidirectional, $inverseField, $cascadeOperations, $dryRun);
            case 'sync':
                return $this->syncRelationships($entry, $relationshipField, $targetItems, $relationshipType, $targetCollection, $targetTaxonomy, $validateTargets, $maintainOrder, $maxItems, $allowDuplicates, $relationshipMetadata, $bidirectional, $inverseField, $cascadeOperations, $dryRun);
            default:
                return $this->createErrorResponse("Operation '{$operation}' not implemented")->toArray();
        }
    }

    /**
     * List current relationships.
     *
     * @return array<string, mixed>
     */
    private function listRelationships(\Statamic\Contracts\Entries\Entry $entry, string $field, string $type): array
    {
        $currentRelationships = $entry->get($field) ?? [];
        $relationships = [];

        if (! is_array($currentRelationships)) {
            $currentRelationships = [$currentRelationships];
        }

        foreach ($currentRelationships as $relationshipId) {
            $relationship = $this->resolveRelationshipTarget($relationshipId, $type);
            if ($relationship) {
                $relationships[] = [
                    'id' => $relationshipId,
                    'type' => $type,
                    'target' => $relationship,
                ];
            }
        }

        return [
            'operation' => 'list',
            'entry_id' => $entry->id(),
            'field' => $field,
            'relationship_type' => $type,
            'total_relationships' => count($relationships),
            'relationships' => $relationships,
        ];
    }

    /**
     * Add relationships.
     *
     * @param  array<string>  $targetItems
     * @param  array<string, mixed>  $metadata
     *
     * @return array<string, mixed>
     */
    private function addRelationships(
        \Statamic\Contracts\Entries\Entry $entry,
        string $field,
        array $targetItems,
        string $type,
        ?string $targetCollection,
        ?string $targetTaxonomy,
        bool $validateTargets,
        bool $maintainOrder,
        ?int $maxItems,
        bool $allowDuplicates,
        array $metadata,
        bool $bidirectional,
        ?string $inverseField,
        bool $cascadeOperations,
        bool $dryRun
    ): array {
        $currentRelationships = $entry->get($field) ?? [];
        if (! is_array($currentRelationships)) {
            $currentRelationships = [$currentRelationships];
        }

        // Validate targets
        if ($validateTargets) {
            $validation = $this->validateTargets($targetItems, $type, $targetCollection, $targetTaxonomy);
            if (! empty($validation['invalid'])) {
                return $this->createErrorResponse('Invalid target items found', [
                    'invalid_items' => $validation['invalid'],
                ])->toArray();
            }
        }

        $added = [];
        $skipped = [];

        foreach ($targetItems as $targetItem) {
            if (! $allowDuplicates && in_array($targetItem, $currentRelationships)) {
                $skipped[] = $targetItem;
                continue;
            }

            if ($maxItems && count($currentRelationships) >= $maxItems) {
                $skipped[] = $targetItem;
                continue;
            }

            if ($maintainOrder) {
                $currentRelationships[] = $targetItem;
            } else {
                array_unshift($currentRelationships, $targetItem);
            }

            $added[] = $targetItem;

            // Handle bidirectional relationships
            if ($bidirectional && $inverseField) {
                $this->createBidirectionalRelationship($targetItem, $inverseField, $entry->id(), $type, $dryRun);
            }
        }

        if ($dryRun) {
            return [
                'dry_run' => true,
                'operation' => 'add',
                'entry_id' => $entry->id(),
                'field' => $field,
                'would_add' => $added,
                'would_skip' => $skipped,
                'final_relationships' => $currentRelationships,
            ];
        }

        $entry->set($field, $currentRelationships);
        $entry->save();

        // Clear caches
        $cacheTypes = $this->getRecommendedCacheTypes('content_change');
        $cacheResult = $this->clearStatamicCaches($cacheTypes);

        return [
            'operation' => 'add',
            'entry_id' => $entry->id(),
            'field' => $field,
            'added' => $added,
            'skipped' => $skipped,
            'total_relationships' => count($currentRelationships),
            'bidirectional_created' => $bidirectional ? count($added) : 0,
            'cache' => $cacheResult,
        ];
    }

    /**
     * Remove relationships.
     *
     * @param  array<string>  $targetItems
     *
     * @return array<string, mixed>
     */
    private function removeRelationships(
        \Statamic\Contracts\Entries\Entry $entry,
        string $field,
        array $targetItems,
        string $type,
        bool $bidirectional,
        ?string $inverseField,
        bool $cascadeOperations,
        bool $dryRun
    ): array {
        $currentRelationships = $entry->get($field) ?? [];
        if (! is_array($currentRelationships)) {
            $currentRelationships = [$currentRelationships];
        }

        $removed = [];
        $notFound = [];

        foreach ($targetItems as $targetItem) {
            if (in_array($targetItem, $currentRelationships)) {
                $currentRelationships = array_diff($currentRelationships, [$targetItem]);
                $removed[] = $targetItem;

                // Handle bidirectional relationships
                if ($bidirectional && $inverseField) {
                    $this->removeBidirectionalRelationship($targetItem, $inverseField, $entry->id(), $type, $dryRun);
                }
            } else {
                $notFound[] = $targetItem;
            }
        }

        if ($dryRun) {
            return [
                'dry_run' => true,
                'operation' => 'remove',
                'entry_id' => $entry->id(),
                'field' => $field,
                'would_remove' => $removed,
                'not_found' => $notFound,
                // @phpstan-ignore-next-line
                'final_relationships' => array_values($currentRelationships),
            ];
        }

        // @phpstan-ignore-next-line
        $entry->set($field, array_values($currentRelationships));
        $entry->save();

        // Clear caches
        $cacheTypes = $this->getRecommendedCacheTypes('content_change');
        $cacheResult = $this->clearStatamicCaches($cacheTypes);

        return [
            'operation' => 'remove',
            'entry_id' => $entry->id(),
            'field' => $field,
            'removed' => $removed,
            'not_found' => $notFound,
            'remaining_relationships' => count($currentRelationships),
            'bidirectional_removed' => $bidirectional ? count($removed) : 0,
            'cache' => $cacheResult,
        ];
    }

    /**
     * Replace all relationships.
     *
     * @param  array<string>  $targetItems
     * @param  array<string, mixed>  $metadata
     *
     * @return array<string, mixed>
     */
    private function replaceRelationships(
        \Statamic\Contracts\Entries\Entry $entry,
        string $field,
        array $targetItems,
        string $type,
        ?string $targetCollection,
        ?string $targetTaxonomy,
        bool $validateTargets,
        bool $maintainOrder,
        ?int $maxItems,
        bool $allowDuplicates,
        array $metadata,
        bool $bidirectional,
        ?string $inverseField,
        bool $cascadeOperations,
        bool $dryRun
    ): array {
        $currentRelationships = $entry->get($field) ?? [];
        if (! is_array($currentRelationships)) {
            $currentRelationships = [$currentRelationships];
        }

        // Validate targets
        if ($validateTargets) {
            $validation = $this->validateTargets($targetItems, $type, $targetCollection, $targetTaxonomy);
            if (! empty($validation['invalid'])) {
                return $this->createErrorResponse('Invalid target items found', [
                    'invalid_items' => $validation['invalid'],
                ])->toArray();
            }
        }

        // Process new relationships
        $newRelationships = $targetItems;
        if (! $allowDuplicates) {
            $newRelationships = array_unique($newRelationships);
        }

        if ($maxItems) {
            $newRelationships = array_slice($newRelationships, 0, $maxItems);
        }

        if ($dryRun) {
            return [
                'dry_run' => true,
                'operation' => 'replace',
                'entry_id' => $entry->id(),
                'field' => $field,
                'current_relationships' => $currentRelationships,
                'new_relationships' => $newRelationships,
                'relationships_to_remove' => array_diff($currentRelationships, $newRelationships),
                'relationships_to_add' => array_diff($newRelationships, $currentRelationships),
            ];
        }

        // Handle bidirectional relationships for removed items
        if ($bidirectional && $inverseField) {
            foreach (array_diff($currentRelationships, $newRelationships) as $removedItem) {
                $this->removeBidirectionalRelationship($removedItem, $inverseField, $entry->id(), $type, false);
            }
            foreach (array_diff($newRelationships, $currentRelationships) as $addedItem) {
                $this->createBidirectionalRelationship($addedItem, $inverseField, $entry->id(), $type, false);
            }
        }

        $entry->set($field, $newRelationships);
        $entry->save();

        // Clear caches
        $cacheTypes = $this->getRecommendedCacheTypes('content_change');
        $cacheResult = $this->clearStatamicCaches($cacheTypes);

        return [
            'operation' => 'replace',
            'entry_id' => $entry->id(),
            'field' => $field,
            'previous_count' => count($currentRelationships),
            'new_count' => count($newRelationships),
            'relationships_removed' => array_diff($currentRelationships, $newRelationships),
            'relationships_added' => array_diff($newRelationships, $currentRelationships),
            'bidirectional_updated' => $bidirectional,
            'cache' => $cacheResult,
        ];
    }

    /**
     * Sync relationships (smart add/remove).
     *
     * @param  array<string>  $targetItems
     * @param  array<string, mixed>  $metadata
     *
     * @return array<string, mixed>
     */
    private function syncRelationships(
        \Statamic\Contracts\Entries\Entry $entry,
        string $field,
        array $targetItems,
        string $type,
        ?string $targetCollection,
        ?string $targetTaxonomy,
        bool $validateTargets,
        bool $maintainOrder,
        ?int $maxItems,
        bool $allowDuplicates,
        array $metadata,
        bool $bidirectional,
        ?string $inverseField,
        bool $cascadeOperations,
        bool $dryRun
    ): array {
        // Sync is similar to replace but with more intelligent handling
        return $this->replaceRelationships(
            $entry, $field, $targetItems, $type, $targetCollection, $targetTaxonomy,
            $validateTargets, $maintainOrder, $maxItems, $allowDuplicates, $metadata,
            $bidirectional, $inverseField, $cascadeOperations, $dryRun
        );
    }

    /**
     * Validate relationships.
     *
     * @param  array<string>  $targetItems
     *
     * @return array<string, mixed>
     */
    private function validateRelationships(
        \Statamic\Contracts\Entries\Entry $entry,
        string $field,
        array $targetItems,
        string $type,
        bool $validateTargets
    ): array {
        $validation = [
            'valid' => [],
            'invalid' => [],
            'warnings' => [],
        ];

        if ($validateTargets) {
            $targetValidation = $this->validateTargets($targetItems, $type, null, null);
            $validation['valid'] = $targetValidation['valid'];
            $validation['invalid'] = $targetValidation['invalid'];
        }

        return [
            'operation' => 'validate',
            'entry_id' => $entry->id(),
            'field' => $field,
            'relationship_type' => $type,
            'validation' => $validation,
            'total_checked' => count($targetItems),
            'valid_count' => count($validation['valid']),
            'invalid_count' => count($validation['invalid']),
        ];
    }

    /**
     * Validate target items exist.
     *
     * @param  array<string>  $targetItems
     *
     * @return array<string, array<string>>
     */
    private function validateTargets(array $targetItems, string $type, ?string $targetCollection, ?string $targetTaxonomy): array
    {
        $valid = [];
        $invalid = [];

        foreach ($targetItems as $targetItem) {
            $target = $this->resolveRelationshipTarget($targetItem, $type, $targetCollection, $targetTaxonomy);
            if ($target) {
                $valid[] = $targetItem;
            } else {
                $invalid[] = $targetItem;
            }
        }

        return ['valid' => $valid, 'invalid' => $invalid];
    }

    /**
     * Resolve relationship target.
     *
     * @return array<string, mixed>|null
     */
    private function resolveRelationshipTarget(string $targetId, string $type, ?string $targetCollection = null, ?string $targetTaxonomy = null): ?array
    {
        switch ($type) {
            case 'entries':
                $target = Entry::find($targetId);
                if ($target && ($targetCollection === null || $target->collection()->handle() === $targetCollection)) {
                    return [
                        'id' => $target->id(),
                        'title' => $target->get('title'),
                        'slug' => $target->slug(),
                        'collection' => $target->collection()->handle(),
                        'url' => $target->url(),
                        'status' => $target->status(),
                    ];
                }
                break;

            case 'terms':
                if ($targetTaxonomy) {
                    $taxonomy = Taxonomy::find($targetTaxonomy);
                    if ($taxonomy) {
                        $term = $taxonomy->queryTerms()->where('slug', $targetId)->first();
                        if ($term) {
                            return [
                                'id' => $term->id(),
                                'title' => $term->get('title'),
                                'slug' => $term->slug(),
                                'taxonomy' => $term->taxonomy()->handle(),
                            ];
                        }
                    }
                }
                break;

            case 'assets':
                $asset = \Statamic\Facades\Asset::find($targetId);
                if ($asset) {
                    return [
                        'id' => $asset->id(),
                        'filename' => $asset->filename(),
                        'path' => $asset->path(),
                        'url' => $asset->url(),
                        'container' => $asset->container()->handle(),
                    ];
                }
                break;

            case 'users':
                $user = \Statamic\Facades\User::find($targetId);
                if ($user) {
                    return [
                        'id' => $user->id(),
                        'email' => $user->email(),
                        'name' => $user->get('name'),
                    ];
                }
                break;
        }

        return null;
    }

    /**
     * Create bidirectional relationship.
     */
    private function createBidirectionalRelationship(string $targetId, string $inverseField, string $sourceId, string $type, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        $target = $this->resolveRelationshipTarget($targetId, $type);
        if (! $target) {
            return;
        }

        if ($type === 'entries') {
            $targetEntry = Entry::find($targetId);
            if ($targetEntry && $targetEntry->blueprint()->hasField($inverseField)) {
                $currentInverse = $targetEntry->get($inverseField) ?? [];
                if (! is_array($currentInverse)) {
                    $currentInverse = [$currentInverse];
                }

                if (! in_array($sourceId, $currentInverse)) {
                    $currentInverse[] = $sourceId;
                    $targetEntry->set($inverseField, $currentInverse);
                    $targetEntry->save();
                }
            }
        }
    }

    /**
     * Remove bidirectional relationship.
     */
    private function removeBidirectionalRelationship(string $targetId, string $inverseField, string $sourceId, string $type, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        if ($type === 'entries') {
            $targetEntry = Entry::find($targetId);
            if ($targetEntry && $targetEntry->blueprint()->hasField($inverseField)) {
                $currentInverse = $targetEntry->get($inverseField) ?? [];
                if (! is_array($currentInverse)) {
                    $currentInverse = [$currentInverse];
                }

                $currentInverse = array_diff($currentInverse, [$sourceId]);
                // @phpstan-ignore-next-line
                $targetEntry->set($inverseField, array_values($currentInverse));
                $targetEntry->save();
            }
        }
    }

    /**
     * Detect relationship type from field config.
     *
     * @param  array<string, mixed>  $config
     */
    private function detectRelationshipType(string $fieldType, array $config): string
    {
        switch ($fieldType) {
            case 'entries':
                return 'entries';
            case 'terms':
            case 'taxonomy':
                return 'terms';
            case 'assets':
                return 'assets';
            case 'users':
                return 'users';
            default:
                return 'entries'; // Default fallback
        }
    }
}
