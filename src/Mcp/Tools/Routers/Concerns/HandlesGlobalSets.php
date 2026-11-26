<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns;

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;
use Statamic\Contracts\Globals\GlobalSet as GlobalSetContract;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;

/**
 * Global set operations for the StructuresRouter.
 */
trait HandlesGlobalSets
{
    /**
     * Handle globalset operations.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function handleGlobalSetAction(string $action, array $arguments): array
    {
        return match ($action) {
            'list' => $this->listGlobalSets($arguments),
            'get' => $this->getGlobalSet($arguments),
            'create' => $this->createGlobalSet($arguments),
            'update' => $this->updateGlobalSet($arguments),
            'delete' => $this->deleteGlobalSet($arguments),
            default => $this->createErrorResponse("Unknown globalset action: {$action}")->toArray(),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function listGlobalSets(array $arguments): array
    {
        $includeDetails = $this->getBooleanArgument($arguments, 'include_details', true);
        $policy = app(ResourcePolicy::class);
        $globalSets = GlobalSet::all()->filter(
            fn ($globalSet): bool => $globalSet instanceof GlobalSetContract
                && $policy->canAccess('structures', $globalSet->handle(), 'read')
        )->map(function ($globalSet) use ($includeDetails) {
            /** @var GlobalSetContract $globalSet */
            $data = [
                'handle' => $globalSet->handle(),
                'title' => $globalSet->title(),
            ];

            if ($includeDetails) {
                $data = array_merge($data, [
                    'blueprint' => $globalSet->blueprint()?->handle(),
                    'sites' => $globalSet->sites()->all(),
                ]);
            }

            return $data;
        })->all();

        return [
            'globalsets' => $globalSets,
            'total' => count($globalSets),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getGlobalSet(array $arguments): array
    {
        $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
        $globalSet = GlobalSet::find($handle);
        if (! $globalSet) {
            return $this->missingResource('Global set', $handle, candidates: GlobalSet::all()->map->handle()->all(), arguments: $arguments)->toArray();
        }
        $data = [
            'handle' => $globalSet->handle(),
            'title' => $globalSet->title(),
            'blueprint' => $globalSet->blueprint()?->handle(),
            'sites' => $globalSet->sites()->all(),
        ];

        return ['globalset' => $data];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function createGlobalSet(array $arguments): array
    {
        if (! $this->hasPermission('create', 'globals')) {
            return $this->createErrorResponse('Permission denied: Cannot create global sets', code: ErrorCode::Forbidden)->toArray();
        }
        $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
        $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : null;
        if (! $handle) {
            return $this->createErrorResponse('Global set handle is required')->toArray();
        }
        if (array_key_exists('handle', $data) && $data['handle'] !== $handle) {
            return $this->createErrorResponse('The new global set\'s handle is the handle argument; data.handle may only repeat it.')->toArray();
        }
        $existsError = $this->checkHandleNotExists(GlobalSet::find($handle), 'Global set', $handle);
        if ($existsError !== null) {
            return $existsError;
        }
        $globalSet = GlobalSet::make($handle);
        if (isset($data['title'])) {
            $globalSet->title($data['title']);
        }
        $this->persistStatamic(fn () => $globalSet->save());
        // Initialize global variables for default site
        $defaultSiteForGlobal = Site::default();
        $defaultSiteHandleForGlobal = is_object($defaultSiteForGlobal) && method_exists($defaultSiteForGlobal, 'handle') ? (string) $defaultSiteForGlobal->handle() : 'default';
        $variables = $globalSet->in($defaultSiteHandleForGlobal);
        if (! $variables) {
            $variables = $globalSet->makeLocalization($defaultSiteHandleForGlobal);
            $this->persistStatamic(fn () => $variables->save());
        }
        // Clear caches
        $this->clearCachesAfterWrite(['stache', 'static']);

        return [
            'globalset' => [
                'handle' => $globalSet->handle(),
                'title' => $globalSet->title(),
                'created' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateGlobalSet(array $arguments): array
    {
        $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
        $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
        $globalSet = GlobalSet::find($handle);
        if (! $globalSet) {
            return $this->missingResource('Global set', $handle, candidates: GlobalSet::all()->map->handle()->all(), arguments: $arguments)->toArray();
        }
        if (isset($data['title'])) {
            $globalSet->title($data['title']);
        }
        $this->persistStatamic(fn () => $globalSet->save());
        // Clear caches
        $this->clearCachesAfterWrite(['stache', 'static']);

        return [
            'globalset' => [
                'handle' => $globalSet->handle(),
                'title' => $globalSet->title(),
                'updated' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deleteGlobalSet(array $arguments): array
    {
        if (! $this->hasPermission('delete', 'globals')) {
            return $this->createErrorResponse('Permission denied: Cannot delete global sets', code: ErrorCode::Forbidden)->toArray();
        }
        $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
        $globalSet = GlobalSet::find($handle);
        if (! $globalSet) {
            return $this->missingResource('Global set', $handle, candidates: GlobalSet::all()->map->handle()->all(), arguments: $arguments)->toArray();
        }
        $this->persistStatamic(fn () => $globalSet->delete());
        // Clear caches
        $this->clearCachesAfterWrite(['stache', 'static']);

        return [
            'globalset' => [
                'handle' => $handle,
                'deleted' => true,
            ],
        ];
    }
}
