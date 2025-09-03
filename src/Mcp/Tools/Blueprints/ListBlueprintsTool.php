<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Blueprints;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Blueprint;

#[Title('List Statamic Blueprints')]
#[IsReadOnly]
class ListBlueprintsTool extends BaseStatamicTool
{
    use HasCommonSchemas;

    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.blueprints.list';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'List all blueprints in a specific namespace (collections, forms, taxonomies, etc.)';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('namespace')
            ->description('Blueprint namespace (collections, forms, taxonomies, globals, assets, users, navs)')
            ->optional()
            ->boolean('include_details')
            ->description('Include blueprint titles and field counts in response')
            ->optional();
    }

    /**
     * Execute the tool.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $namespace = $arguments['namespace'] ?? 'collections';
        $includeDetails = $arguments['include_details'] ?? false;

        try {
            $blueprintsCollection = Blueprint::in($namespace);

            if ($includeDetails) {
                $blueprints = collect($blueprintsCollection->all())->map(function ($blueprint, $handle) {
                    /** @var array<string, mixed> $contents */
                    $contents = $blueprint->contents();

                    // Extract sections from tabs structure (Statamic v5+)
                    $allSections = [];
                    $tabs = $contents['tabs'] ?? [];
                    foreach ($tabs as $tabName => $tab) {
                        if (isset($tab['sections']) && is_array($tab['sections'])) {
                            $allSections = array_merge($allSections, $tab['sections']);
                        }
                    }

                    // Fallback to direct sections for older format
                    if (empty($allSections) && isset($contents['sections'])) {
                        $allSections = $contents['sections'];
                    }

                    /** @var array<int, array<string, mixed>> $allSectionsTyped */
                    $allSectionsTyped = $allSections;
                    $fieldCount = collect($allSectionsTyped)->sum(fn (array $section): int => count($section['fields'] ?? []));

                    return [
                        'handle' => $handle,
                        'title' => $blueprint->title(),
                        'tabs_count' => count($tabs),
                        'sections_count' => count($allSections),
                        'field_count' => $fieldCount,
                    ];
                })->values()->all();
            } else {
                $blueprints = collect($blueprintsCollection->all())->keys()->values()->all();
            }

            return [
                'blueprints' => $blueprints,
                'count' => count($blueprints),
                'namespace' => $namespace,
                'includes_details' => $includeDetails,
            ];
        } catch (\Exception $e) {
            return [
                'error' => "Failed to list blueprints in namespace '{$namespace}': " . $e->getMessage(),
                'namespace' => $namespace,
                'blueprints' => [],
                'count' => 0,
                'includes_details' => $includeDetails,
            ];
        }
    }
}
