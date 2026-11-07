<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns;

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
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
            'configure' => $this->configureGlobalSet($arguments),
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
        try {
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
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to list global sets: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getGlobalSet(array $arguments): array
    {
        try {
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
            $globalSet = GlobalSet::find($handle);

            if (! $globalSet) {
                return $this->createErrorResponse("Global set not found: {$handle}")->toArray();
            }

            $data = [
                'handle' => $globalSet->handle(),
                'title' => $globalSet->title(),
                'blueprint' => $globalSet->blueprint()?->handle(),
                'sites' => $globalSet->sites()->all(),
            ];

            return ['globalset' => $data];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to get global set: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function createGlobalSet(array $arguments): array
    {
        if (! $this->hasPermission('create', 'globals')) {
            return $this->createErrorResponse('Permission denied: Cannot create global sets')->toArray();
        }

        try {
            $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
            $handle = is_string($data['handle'] ?? null) ? $data['handle'] : (is_string($arguments['handle'] ?? null) ? $arguments['handle'] : null);

            if (! $handle) {
                return $this->createErrorResponse('Global set handle is required')->toArray();
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
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to create global set: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateGlobalSet(array $arguments): array
    {
        if (! $this->hasPermission('edit', 'globals')) {
            return $this->createErrorResponse('Permission denied: Cannot update global sets')->toArray();
        }

        try {
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
            $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];

            $globalSet = GlobalSet::find($handle);
            if (! $globalSet) {
                return $this->createErrorResponse("Global set not found: {$handle}")->toArray();
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
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to update global set: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deleteGlobalSet(array $arguments): array
    {
        if (! $this->hasPermission('delete', 'globals')) {
            return $this->createErrorResponse('Permission denied: Cannot delete global sets')->toArray();
        }

        try {
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
            $globalSet = GlobalSet::find($handle);

            if (! $globalSet) {
                return $this->createErrorResponse("Global set not found: {$handle}")->toArray();
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
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to delete global set: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function configureGlobalSet(array $arguments): array
    {
        try {
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
            $config = is_array($arguments['config'] ?? null) ? $arguments['config'] : [];

            $globalSet = GlobalSet::find($handle);
            if (! $globalSet) {
                return $this->createErrorResponse("Global set not found: {$handle}")->toArray();
            }

            // Handle specific configuration options
            if (isset($config['title'])) {
                $globalSet->title($config['title']);
            }

            // Save the global set
            $this->persistStatamic(fn () => $globalSet->save());

            // Clear caches
            $this->clearCachesAfterWrite(['stache']);

            return [
                'globalset' => [
                    'handle' => $globalSet->handle(),
                    'title' => $globalSet->title(),
                    'config' => $globalSet->fileData(),
                ],
                'configured' => true,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to configure global set: {$e->getMessage()}")->toArray();
        }
    }
}
