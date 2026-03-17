<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns;

use Statamic\Facades\Taxonomy;

/**
 * Taxonomy operations for the StructuresRouter.
 */
trait HandlesTaxonomies
{
    /**
     * Handle taxonomy operations.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function handleTaxonomyAction(string $action, array $arguments): array
    {
        return match ($action) {
            'list' => $this->listTaxonomies($arguments),
            'get' => $this->getTaxonomy($arguments),
            'create' => $this->createTaxonomy($arguments),
            'update' => $this->updateTaxonomy($arguments),
            'delete' => $this->deleteTaxonomy($arguments),
            'configure' => $this->configureTaxonomy($arguments),
            default => $this->createErrorResponse("Unknown taxonomy action: {$action}")->toArray(),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function listTaxonomies(array $arguments): array
    {
        try {
            $includeDetails = $this->getBooleanArgument($arguments, 'include_details', true);
            $limit = $this->getIntegerArgument($arguments, 'limit', 50, 1, 500);
            $offset = $this->getIntegerArgument($arguments, 'offset', 0, 0);

            $allTaxonomies = Taxonomy::all();
            $total = $allTaxonomies->count();

            $taxonomies = $allTaxonomies->skip($offset)->take($limit)->map(function ($taxonomy) use ($includeDetails) {
                /** @var \Statamic\Contracts\Taxonomies\Taxonomy $taxonomy */
                $data = [
                    'handle' => $taxonomy->handle(),
                    'title' => $taxonomy->title(),
                ];

                if ($includeDetails) {
                    $data = array_merge($data, [
                        'blueprint' => $taxonomy->termBlueprints()->first()?->handle(),
                        'sites' => $taxonomy->sites()->all(),
                        'collections' => $taxonomy->collections()->map->handle()->all(),
                    ]);
                }

                return $data;
            })->values()->all();

            return [
                'taxonomies' => $taxonomies,
                'total' => $total,
                'pagination' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $limit) < $total,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to list taxonomies: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getTaxonomy(array $arguments): array
    {
        try {
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
            $taxonomy = Taxonomy::find($handle);

            if (! $taxonomy) {
                return $this->createErrorResponse("Taxonomy not found: {$handle}")->toArray();
            }

            $data = [
                'handle' => $taxonomy->handle(),
                'title' => $taxonomy->title(),
                'blueprints' => $taxonomy->termBlueprints()->map->handle()->all(),
                'sites' => $taxonomy->sites()->all(),
                'collections' => $taxonomy->collections()->map->handle()->all(),
                'term_count' => $taxonomy->queryTerms()->count(),
            ];

            return ['taxonomy' => $data];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to get taxonomy: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function createTaxonomy(array $arguments): array
    {
        if (! $this->hasPermission('create', 'taxonomies')) {
            return $this->createErrorResponse('Permission denied: Cannot create taxonomies')->toArray();
        }

        try {
            $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
            $handle = is_string($data['handle'] ?? null) ? $data['handle'] : (is_string($arguments['handle'] ?? null) ? $arguments['handle'] : null);

            if (! $handle) {
                return $this->createErrorResponse('Taxonomy handle is required')->toArray();
            }

            $existsError = $this->checkHandleNotExists(Taxonomy::find($handle), 'Taxonomy', $handle);
            if ($existsError !== null) {
                return $existsError;
            }

            $taxonomy = Taxonomy::make($handle);

            if (isset($data['title'])) {
                $taxonomy->title($data['title']);
            }

            $taxonomy->save();

            // Clear caches
            $this->clearStatamicCaches(['stache', 'static']);

            return [
                'taxonomy' => [
                    'handle' => $taxonomy->handle(),
                    'title' => $taxonomy->title(),
                    'created' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to create taxonomy: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateTaxonomy(array $arguments): array
    {
        if (! $this->hasPermission('edit', 'taxonomies')) {
            return $this->createErrorResponse('Permission denied: Cannot update taxonomies')->toArray();
        }

        try {
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
            $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];

            $taxonomy = Taxonomy::find($handle);
            if (! $taxonomy) {
                return $this->createErrorResponse("Taxonomy not found: {$handle}")->toArray();
            }

            if (isset($data['title'])) {
                $taxonomy->title($data['title']);
            }

            $taxonomy->save();

            // Clear caches
            $this->clearStatamicCaches(['stache', 'static']);

            return [
                'taxonomy' => [
                    'handle' => $taxonomy->handle(),
                    'title' => $taxonomy->title(),
                    'updated' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to update taxonomy: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deleteTaxonomy(array $arguments): array
    {
        if (! $this->hasPermission('delete', 'taxonomies')) {
            return $this->createErrorResponse('Permission denied: Cannot delete taxonomies')->toArray();
        }

        try {
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
            $taxonomy = Taxonomy::find($handle);

            if (! $taxonomy) {
                return $this->createErrorResponse("Taxonomy not found: {$handle}")->toArray();
            }

            $force = $this->getBooleanArgument($arguments, 'force', false);
            $termCount = $taxonomy->queryTerms()->count();

            if ($termCount > 0 && ! $force) {
                return $this->createErrorResponse(
                    "Cannot delete taxonomy '{$handle}' — it contains {$termCount} terms. "
                    . 'Use force: true to delete the taxonomy with all its terms and blueprints.'
                )->toArray();
            }

            // Cascade: delete terms first
            if ($termCount > 0) {
                $taxonomy->queryTerms()->get()->each->delete();
            }

            // Cascade: delete blueprints
            foreach ($taxonomy->termBlueprints() as $blueprint) {
                $blueprint->delete();
            }

            $taxonomy->delete();

            // Clear caches
            $this->clearStatamicCaches(['stache', 'static']);

            return [
                'taxonomy' => [
                    'handle' => $handle,
                    'deleted' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to delete taxonomy: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function configureTaxonomy(array $arguments): array
    {
        try {
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
            $config = is_array($arguments['config'] ?? null) ? $arguments['config'] : [];

            $taxonomy = Taxonomy::find($handle);
            if (! $taxonomy) {
                return $this->createErrorResponse("Taxonomy not found: {$handle}")->toArray();
            }

            // Handle specific configuration options
            if (isset($config['title'])) {
                $taxonomy->title($config['title']);
            }

            if (isset($config['preview_targets'])) {
                $taxonomy->previewTargets($config['preview_targets']);
            }

            if (isset($config['default_status'])) {
                $taxonomy->defaultStatus($config['default_status']);
            }

            if (isset($config['collections'])) {
                $taxonomy->collections($config['collections']);
            }

            // Save the taxonomy
            $taxonomy->save();

            // Clear caches
            $this->clearStatamicCaches(['stache']);

            return [
                'taxonomy' => [
                    'handle' => $taxonomy->handle(),
                    'title' => $taxonomy->title(),
                    'config' => $taxonomy->toArray(),
                ],
                'configured' => true,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to configure taxonomy: {$e->getMessage()}")->toArray();
        }
    }
}
