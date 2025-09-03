<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Globals;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Blueprint;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;

#[Title('Create Global Set')]
class CreateGlobalSetTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.globals.sets.create';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Create a new global set structure with optional blueprint';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('handle')
            ->description('Unique handle for the global set')
            ->required()
            ->string('title')
            ->description('Display title for the global set')
            ->optional()
            ->raw('sites', [
                'type' => 'array',
                'description' => 'Site handles this global set should be available in (defaults to all sites)',
                'items' => ['type' => 'string'],
            ])
            ->optional()
            ->string('blueprint')
            ->description('Blueprint handle to use (optional)')
            ->optional()
            ->raw('initial_values', [
                'type' => 'object',
                'description' => 'Initial values to set for the default site',
                'additionalProperties' => true,
            ])
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
        $handle = $arguments['handle'];
        $title = $arguments['title'] ?? ucwords(str_replace(['_', '-'], ' ', $handle));
        $sites = $arguments['sites'] ?? Site::all()->map->handle()->all();
        $blueprintHandle = $arguments['blueprint'] ?? null;
        $initialValues = $arguments['initial_values'] ?? [];

        try {
            // Check if global set already exists
            if (GlobalSet::findByHandle($handle)) {
                return $this->createErrorResponse("Global set with handle '{$handle}' already exists", [
                    'existing_sets' => GlobalSet::all()->map->handle()->all(),
                ])->toArray();
            }

            // Validate sites
            $availableSites = Site::all()->map->handle()->all();
            $invalidSites = array_diff($sites, $availableSites);
            if (! empty($invalidSites)) {
                return $this->createErrorResponse('Invalid site handles provided', [
                    'invalid_sites' => $invalidSites,
                    'available_sites' => $availableSites,
                ])->toArray();
            }

            // Validate blueprint if provided
            $blueprint = null;
            if ($blueprintHandle) {
                $blueprint = Blueprint::find("globals.{$blueprintHandle}") ?? Blueprint::find($blueprintHandle);
                if (! $blueprint) {
                    return $this->createErrorResponse("Blueprint '{$blueprintHandle}' not found", [
                        'note' => 'Blueprint will be looked up in globals namespace first, then global namespace',
                    ])->toArray();
                }
            }

            // Create the global set
            $globalSet = GlobalSet::make()
                ->handle($handle)
                ->title($title)
                ->sites($sites);

            if ($blueprint) {
                $globalSet->blueprint($blueprint->handle());
            }

            $globalSet->save();

            // Set initial values if provided
            if (! empty($initialValues)) {
                $defaultSite = Site::default()->handle();
                if (in_array($defaultSite, $sites)) {
                    $localizedSet = $globalSet->in($defaultSite);
                    $localizedSet->data($initialValues);
                    $localizedSet->save();
                }
            }

            // Clear relevant caches
            Stache::clear();

            return [
                'success' => true,
                'global_set' => [
                    'handle' => $handle,
                    'title' => $title,
                    'sites' => $sites,
                    'blueprint' => $blueprintHandle,
                    'has_initial_values' => ! empty($initialValues),
                ],
                'initial_values' => $initialValues,
                'metadata' => [
                    'created_at' => now()->toISOString(),
                    'file_path' => $globalSet->path(),
                    'available_in_sites' => $sites,
                ],
                'next_steps' => [
                    'add_values' => "Use 'statamic.globals.values.update' to add content",
                    'create_blueprint' => $blueprintHandle ? null : 'Consider creating a blueprint for structured fields',
                    'localize' => count($sites) > 1 ? 'Add localized content for other sites' : null,
                ],
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to create global set: ' . $e->getMessage())->toArray();
        }
    }
}
