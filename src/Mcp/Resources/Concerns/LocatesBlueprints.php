<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Resources\Concerns;

use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Taxonomy;
use Statamic\Fields\Blueprint as BlueprintInstance;

/**
 * Blueprint lookup shared by the blueprint resources.
 *
 * Statamic keeps entry and term blueprints in per-collection and per-taxonomy
 * namespaces (`collections.pages`) rather than under the bare `collections`
 * namespace, so both have to be walked to see everything.
 */
trait LocatesBlueprints
{
    /** Namespaces that hold blueprints directly. */
    private const BASE_NAMESPACES = ['collections', 'taxonomies', 'globals', 'forms', 'assets', 'users'];

    /**
     * @return list<BlueprintInstance>
     */
    protected function allBlueprints(): array
    {
        $blueprints = [];

        foreach ($this->namespaces() as $namespace) {
            foreach ($this->blueprintsIn($namespace) as $blueprint) {
                $blueprints[] = $blueprint;
            }
        }

        return $blueprints;
    }

    /**
     * Find one blueprint by namespace and handle, or null when it does not exist.
     */
    protected function findBlueprint(string $namespace, string $handle): ?BlueprintInstance
    {
        foreach ($this->blueprintsIn($namespace) as $blueprint) {
            if ($blueprint->handle() === $handle) {
                return $blueprint;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function namespaces(): array
    {
        $namespaces = self::BASE_NAMESPACES;

        foreach (Collection::handles() as $handle) {
            if (is_string($handle)) {
                $namespaces[] = "collections.{$handle}";
            }
        }

        foreach (Taxonomy::handles() as $handle) {
            if (is_string($handle)) {
                $namespaces[] = "taxonomies.{$handle}";
            }
        }

        return $namespaces;
    }

    /**
     * @return list<BlueprintInstance>
     */
    private function blueprintsIn(string $namespace): array
    {
        try {
            $found = Blueprint::in($namespace)->all();
        } catch (\Throwable) {
            // A namespace whose backing collection/taxonomy was removed mid-read.
            return [];
        }

        $blueprints = [];

        foreach ($found as $blueprint) {
            if ($blueprint instanceof BlueprintInstance) {
                $blueprints[] = $blueprint;
            }
        }

        return $blueprints;
    }
}
