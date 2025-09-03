<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Blueprints;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Blueprint;
use Symfony\Component\Yaml\Yaml;

#[Title('Scan Statamic Blueprints')]
#[IsReadOnly]
class ScanBlueprintsTool extends BaseStatamicTool
{
    private int $totalFound = 0;

    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.blueprints.scan';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Parse blueprints and fieldsets into normalized schema with performance optimization';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('paths')
            ->description('Comma-separated list of paths to scan for blueprints (optional)')
            ->optional()
            ->integer('limit')
            ->description('Limit number of blueprints returned (default: 50)')
            ->optional()
            ->boolean('include_fields')
            ->description('Include detailed field information (default: true)')
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
        $paths = $this->getPaths($arguments);
        $limit = $arguments['limit'] ?? 50;
        $includeFields = $arguments['include_fields'] ?? true;

        $blueprints = $this->scanBlueprints(array_values($paths), $limit, $includeFields);

        return [
            'blueprints' => $blueprints,
            'count' => count($blueprints),
            'total_found' => $this->totalFound,
            'limit_applied' => $limit,
            'include_fields' => $includeFields,
            'paths_scanned' => $paths,
        ];
    }

    /**
     * Get the paths to scan for blueprints.
     */
    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<int, string>
     */
    private function getPaths(array $arguments): array
    {
        if (! empty($arguments['paths'])) {
            return array_map('trim', explode(',', $arguments['paths']));
        }

        // Default paths from config or Statamic defaults
        $defaultPaths = [
            resource_path('blueprints'),
            resource_path('fieldsets'),
        ];

        // Add vendor blueprints if they exist
        if (File::exists(base_path('vendor/statamic/cms/resources/blueprints'))) {
            $defaultPaths[] = base_path('vendor/statamic/cms/resources/blueprints');
        }

        return array_values(array_filter($defaultPaths, fn ($path) => File::exists($path)));
    }

    /**
     * Scan the given paths for blueprints and normalize them.
     *
     * @param  array<int, string>  $paths
     *
     * @return array<string, mixed>
     */
    private function scanBlueprints(array $paths, int $limit = 50, bool $includeFields = true): array
    {
        $blueprints = [];
        $totalFound = 0;

        foreach ($paths as $path) {
            if (! File::exists($path)) {
                continue;
            }

            // Find YAML files both directly in path and in subdirectories
            $yamlFiles = array_merge(
                glob($path . '/*.yaml') ?: [],
                glob($path . '/**/*.yaml') ?: []
            );

            if (empty($yamlFiles)) {
                continue;
            }

            foreach ($yamlFiles as $filePath) {
                $totalFound++;

                // Apply limit early to avoid unnecessary processing
                if (count($blueprints) >= $limit) {
                    break 2; // Break out of both loops
                }

                $handle = $this->getBlueprintHandle($filePath, $path);

                // Skip if already processed (prevent duplicates)
                if (isset($blueprints[$handle])) {
                    continue;
                }

                // Optimize: Parse blueprint lazily - skip heavy parsing if not including fields
                $content = $this->parseBlueprint($filePath, $includeFields);

                if ($content) {
                    $blueprints[$handle] = $content;
                }
            }
        }

        $this->totalFound = $totalFound;

        return $blueprints;
    }

    /**
     * Get the blueprint handle from its file path.
     */
    private function getBlueprintHandle(string $filePath, string $basePath): string
    {
        $relativePath = str_replace($basePath . '/', '', $filePath);
        $handle = str_replace('.yaml', '', $relativePath);
        $handle = str_replace('/', '.', $handle);

        return $handle;
    }

    /**
     * Parse a blueprint file and normalize its structure.
     *
     * @return array<string, mixed>|null
     */
    private function parseBlueprint(string $filePath, bool $includeFields = true): ?array
    {
        try {
            $content = Yaml::parseFile($filePath);

            if (! is_array($content)) {
                return null;
            }

            return $this->normalizeBlueprint($content, $includeFields);
        } catch (\Exception $e) {
            // Log error or handle it silently
            return null;
        }
    }

    /**
     * Normalize blueprint structure.
     *
     * @param  array<string, mixed>  $blueprint
     *
     * @return array<string, mixed>
     */
    private function normalizeBlueprint(array $blueprint, bool $includeFields = true): array
    {
        $normalized = [
            'title' => $blueprint['title'] ?? null,
            'sections' => [],
        ];

        if ($includeFields) {
            $normalized['fields'] = [];
            $normalized['sets'] = [];
        } else {
            // Only include field counts and handles for performance
            $normalized['field_count'] = 0;
            $normalized['field_handles'] = [];
        }

        // Process sections (for collection blueprints)
        if (isset($blueprint['sections'])) {
            foreach ($blueprint['sections'] as $sectionKey => $section) {
                if ($includeFields) {
                    $normalized['sections'][$sectionKey] = [
                        'display' => $section['display'] ?? $sectionKey,
                        'fields' => $this->normalizeFields($section['fields'] ?? []),
                    ];
                } else {
                    $normalized['sections'][$sectionKey] = [
                        'display' => $section['display'] ?? $sectionKey,
                        'field_count' => count($section['fields'] ?? []),
                    ];
                    // Collect field handles for summary
                    foreach ($section['fields'] ?? [] as $field) {
                        if (isset($field['handle'])) {
                            $normalized['field_handles'][] = $field['handle'];
                        }
                    }
                }
            }
        }

        // Process tabs (for some blueprints)
        if (isset($blueprint['tabs'])) {
            foreach ($blueprint['tabs'] as $tabKey => $tab) {
                if ($includeFields) {
                    $normalized['sections'][$tabKey] = [
                        'display' => $tab['display'] ?? $tabKey,
                        'fields' => $this->normalizeFields($tab['fields'] ?? []),
                    ];
                } else {
                    $normalized['sections'][$tabKey] = [
                        'display' => $tab['display'] ?? $tabKey,
                        'field_count' => count($tab['fields'] ?? []),
                    ];
                    // Collect field handles for summary
                    foreach ($tab['fields'] ?? [] as $field) {
                        if (isset($field['handle'])) {
                            $normalized['field_handles'][] = $field['handle'];
                        }
                    }
                }
            }
        }

        // Process direct fields (for simple blueprints)
        if (isset($blueprint['fields']) && ! isset($blueprint['sections']) && ! isset($blueprint['tabs'])) {
            if ($includeFields) {
                $normalized['fields'] = $this->normalizeFields($blueprint['fields']);
            } else {
                $normalized['field_count'] = count($blueprint['fields']);
                foreach ($blueprint['fields'] as $field) {
                    if (isset($field['handle'])) {
                        $normalized['field_handles'][] = $field['handle'];
                    }
                }
            }
        }

        // Process sets (for Bard/Replicator) - only if including fields
        if ($includeFields && isset($blueprint['sets'])) {
            $normalized['sets'] = $this->normalizeSets($blueprint['sets']);
        }

        // Extract all fields into flat array for easy access - only if including fields
        if ($includeFields) {
            $normalized['fields'] = $this->extractAllFields($normalized);
        } else {
            // Set total field count
            $fieldHandles = $normalized['field_handles'] ?? [];
            $normalized['field_count'] = count($fieldHandles);
            $normalized['field_handles'] = array_unique($fieldHandles);
        }

        return $normalized;
    }

    /**
     * Normalize fields array.
     *
     * @param  array<int, mixed>  $fields
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeFields(array $fields): array
    {
        $normalized = [];

        foreach ($fields as $field) {
            if (! isset($field['handle'])) {
                continue;
            }

            $normalizedField = [
                'handle' => $field['handle'],
                'field' => [
                    'type' => $field['field']['type'] ?? 'text',
                    'display' => $field['field']['display'] ?? $field['handle'],
                    'required' => $field['field']['required'] ?? false,
                    'validate' => $field['field']['validate'] ?? null,
                    'instructions' => $field['field']['instructions'] ?? null,
                ],
            ];

            // Add field-specific configurations
            if (isset($field['field']['sets'])) {
                $normalizedField['field']['sets'] = $this->normalizeSets($field['field']['sets']);
            }

            if (isset($field['field']['collections'])) {
                $normalizedField['field']['collections'] = $field['field']['collections'];
            }

            if (isset($field['field']['container'])) {
                $normalizedField['field']['container'] = $field['field']['container'];
            }

            if (isset($field['field']['taxonomies'])) {
                $normalizedField['field']['taxonomies'] = $field['field']['taxonomies'];
            }

            $normalized[] = $normalizedField;
        }

        return $normalized;
    }

    /**
     * Normalize sets for Bard/Replicator fields.
     *
     * @param  array<string, mixed>  $sets
     *
     * @return array<string, mixed>
     */
    private function normalizeSets(array $sets): array
    {
        $normalized = [];

        foreach ($sets as $setKey => $set) {
            $normalized[$setKey] = [
                'display' => $set['display'] ?? $setKey,
                'fields' => $this->normalizeFields($set['fields'] ?? []),
                'instructions' => $set['instructions'] ?? null,
                'icon' => $set['icon'] ?? null,
            ];
        }

        return $normalized;
    }

    /**
     * Extract all fields from sections into a flat array.
     *
     * @param  array<string, mixed>  $blueprint
     *
     * @return array<string, mixed>
     */
    private function extractAllFields(array $blueprint): array
    {
        $fields = [];

        // From sections
        foreach ($blueprint['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                $fields[$field['handle']] = $field['field'];
            }
        }

        // From direct fields
        foreach ($blueprint['fields'] as $field) {
            if (isset($field['handle'])) {
                $fields[$field['handle']] = $field['field'];
            }
        }

        return $fields;
    }
}
