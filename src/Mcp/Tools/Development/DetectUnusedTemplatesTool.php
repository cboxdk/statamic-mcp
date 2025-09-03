<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Development;

use Cboxdk\StatamicMcp\Mcp\Security\PathValidator;
use Cboxdk\StatamicMcp\Mcp\Support\ToolLogger;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use InvalidArgumentException;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

#[Title('Detect Unused Templates')]
#[IsReadOnly]
class DetectUnusedTemplatesTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.templates.detect-unused';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Detect unused templates, partials, and layouts to help clean up template directories';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('template_directory')
            ->description('Template directory to analyze (default: resources/views)')
            ->optional()
            ->boolean('include_partials')
            ->description('Include analysis of partial templates')
            ->optional()
            ->boolean('include_layouts')
            ->description('Include analysis of layout templates')
            ->optional()
            ->boolean('check_collections')
            ->description('Check templates used by collections and entries')
            ->optional()
            ->boolean('deep_scan')
            ->description('Perform deep scan of template references in code')
            ->optional()
            ->integer('days_since_modified')
            ->description('Consider templates unused if not modified in X days')
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
        $templateDirectory = $arguments['template_directory'] ?? 'resources/views';
        $includePartials = $arguments['include_partials'] ?? true;
        $includeLayouts = $arguments['include_layouts'] ?? true;
        $checkCollections = $arguments['check_collections'] ?? true;
        $deepScan = $arguments['deep_scan'] ?? true;
        $daysSinceModified = $arguments['days_since_modified'] ?? null;

        try {
            $basePath = base_path($templateDirectory);

            if (! is_dir($basePath)) {
                return $this->createErrorResponse("Template directory '{$templateDirectory}' not found")->toArray();
            }

            $analysis = [
                'unused_templates' => [],
                'unused_partials' => [],
                'unused_layouts' => [],
                'referenced_templates' => [],
                'statistics' => [
                    'total_templates' => 0,
                    'unused_templates' => 0,
                    'unused_partials' => 0,
                    'unused_layouts' => 0,
                    'disk_space_saved' => 0,
                ],
                'recommendations' => [],
            ];

            // Discover all templates
            $allTemplates = $this->discoverTemplates($basePath);
            $analysis['statistics']['total_templates'] = count($allTemplates);

            // Find referenced templates
            $referencedTemplates = $this->findReferencedTemplates($allTemplates, $checkCollections, $deepScan);
            $analysis['referenced_templates'] = $referencedTemplates;

            // Identify unused templates
            $unusedTemplates = $this->identifyUnusedTemplates($allTemplates, $referencedTemplates, $daysSinceModified);

            // Categorize unused templates
            foreach ($unusedTemplates as $template) {
                $category = $this->categorizeTemplate($template['relative_path']);

                switch ($category) {
                    case 'partial':
                        if ($includePartials) {
                            $analysis['unused_partials'][] = $template;
                            $analysis['statistics']['unused_partials']++;
                        }
                        break;
                    case 'layout':
                        if ($includeLayouts) {
                            $analysis['unused_layouts'][] = $template;
                            $analysis['statistics']['unused_layouts']++;
                        }
                        break;
                    default:
                        $analysis['unused_templates'][] = $template;
                        $analysis['statistics']['unused_templates']++;
                }

                $analysis['statistics']['disk_space_saved'] += $template['size'];
            }

            // Generate recommendations
            $analysis['recommendations'] = $this->generateRecommendations($analysis);

            return [
                'analysis' => $analysis,
                'summary' => [
                    'status' => $this->getCleanupStatus($analysis['statistics']),
                    'cleanup_potential' => $this->formatFileSize($analysis['statistics']['disk_space_saved']),
                    'unused_percentage' => $analysis['statistics']['total_templates'] > 0
                        ? round(($analysis['statistics']['unused_templates'] + $analysis['statistics']['unused_partials'] + $analysis['statistics']['unused_layouts']) / $analysis['statistics']['total_templates'] * 100, 1)
                        : 0,
                    'most_common_unused_type' => $this->getMostCommonUnusedType($analysis['statistics']),
                ],
                'cleanup_suggestions' => $this->generateCleanupSuggestions($analysis),
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to detect unused templates: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Discover all templates in directory.
     *
     *
     * @return array<array<string, mixed>>
     */
    private function discoverTemplates(string $basePath): array
    {
        $templates = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (in_array($file->getExtension(), ['html', 'php', 'antlers', 'blade'])) {
                $relativePath = str_replace($basePath . '/', '', $file->getPathname());

                $templates[] = [
                    'path' => $file->getPathname(),
                    'relative_path' => $relativePath,
                    'name' => $file->getBasename(),
                    'extension' => $file->getExtension(),
                    'size' => $file->getSize(),
                    'modified' => $file->getMTime(),
                    'modified_date' => date('Y-m-d H:i:s', $file->getMTime()),
                ];
            }
        }

        return $templates;
    }

    /**
     * Find all referenced templates.
     *
     * @param  array<array<string, mixed>>  $allTemplates
     *
     * @return array<string, mixed>
     */
    private function findReferencedTemplates(array $allTemplates, bool $checkCollections, bool $deepScan): array
    {
        $referenced = [
            'templates' => [],
            'partials' => [],
            'layouts' => [],
            'collection_templates' => [],
            'entry_templates' => [],
        ];

        // Check collection and entry templates
        if ($checkCollections) {
            $collectionReferences = $this->findCollectionTemplateReferences();
            $referenced = array_merge_recursive($referenced, $collectionReferences);
        }

        // Scan template files for references
        foreach ($allTemplates as $template) {
            try {
                // Validate path security
                PathValidator::validatePath($template['path'], PathValidator::getAllowedTemplatePaths());

                $content = file_get_contents($template['path']);
                if ($content === false) {
                    continue;
                }
            } catch (InvalidArgumentException $e) {
                // Log security warning and skip this file
                ToolLogger::securityWarning($this->getToolName(), 'Path traversal attempt detected', [
                    'path' => $template['path'],
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
            $templateReferences = $this->findTemplateReferences($content, $template['relative_path']);

            foreach ($templateReferences as $type => $refs) {
                $referenced[$type] = array_merge($referenced[$type], $refs);
            }
        }

        // Deep scan codebase if requested
        if ($deepScan) {
            $codeReferences = $this->deepScanCodebase();
            $referenced = array_merge_recursive($referenced, $codeReferences);
        }

        // Remove duplicates
        foreach ($referenced as $type => &$refs) {
            $refs = array_unique($refs);
        }

        return $referenced;
    }

    /**
     * Find collection template references.
     *
     * @return array<string, array<string>>
     */
    private function findCollectionTemplateReferences(): array
    {
        $references = [
            'collection_templates' => [],
            'entry_templates' => [],
            'templates' => [],
        ];

        try {
            // Check collection templates
            foreach (Collection::all() as $collection) {
                if ($template = $collection->template()) {
                    $references['collection_templates'][] = $template;
                    $references['templates'][] = $template;
                }

                if ($layout = $collection->layout()) {
                    $references['layouts'][] = $layout;
                }
            }

            // Check entry templates
            foreach (Entry::all() as $entry) {
                if ($template = $entry->template()) {
                    $references['entry_templates'][] = $template;
                    $references['templates'][] = $template;
                }

                if ($layout = $entry->layout()) {
                    $references['layouts'][] = $layout;
                }
            }
        } catch (\Exception $e) {
            // If we can't access collections/entries, continue without them
        }

        return $references;
    }

    /**
     * Find template references in content.
     *
     *
     * @return array<string, array<string>>
     */
    private function findTemplateReferences(string $content, string $currentTemplate): array
    {
        $references = [
            'templates' => [],
            'partials' => [],
            'layouts' => [],
        ];

        // Blade references
        if (str_contains($content, '@')) {
            // @extends references
            if (preg_match_all('/@extends\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
                $references['layouts'] = array_merge($references['layouts'], $matches[1]);
            }

            // @include references
            if (preg_match_all('/@include\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
                $references['partials'] = array_merge($references['partials'], $matches[1]);
            }

            // @component references
            if (preg_match_all('/@component\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
                $references['templates'] = array_merge($references['templates'], $matches[1]);
            }
        }

        // Antlers references
        if (str_contains($content, '{{')) {
            // {{ partial: }} references
            if (preg_match_all('/\{\{\s*partial:\s*([^}\s]+)/', $content, $matches)) {
                $references['partials'] = array_merge($references['partials'], $matches[1]);
            }

            // {{ layout: }} references
            if (preg_match_all('/\{\{\s*layout:\s*([^}\s]+)/', $content, $matches)) {
                $references['layouts'] = array_merge($references['layouts'], $matches[1]);
            }

            // {{ template: }} references
            if (preg_match_all('/\{\{\s*template:\s*([^}\s]+)/', $content, $matches)) {
                $references['templates'] = array_merge($references['templates'], $matches[1]);
            }
        }

        return $references;
    }

    /**
     * Deep scan codebase for template references.
     *
     * @return array<string, array<string>>
     */
    private function deepScanCodebase(): array
    {
        $references = [
            'templates' => [],
            'partials' => [],
            'layouts' => [],
        ];

        $directories = [
            'app',
            'config',
            'routes',
        ];

        foreach ($directories as $dir) {
            $dirPath = base_path($dir);
            if (! is_dir($dirPath)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dirPath, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    try {
                        // Validate path security for app/config/routes directories
                        $allowedPaths = [base_path('app'), base_path('config'), base_path('routes')];
                        PathValidator::validatePath($file->getPathname(), $allowedPaths);

                        $content = file_get_contents($file->getPathname());
                        if ($content === false) {
                            continue;
                        }
                    } catch (InvalidArgumentException $e) {
                        // Log security warning and skip this file
                        ToolLogger::securityWarning($this->getToolName(), 'Path traversal attempt detected', [
                            'path' => $file,
                            'error' => $e->getMessage(),
                        ]);
                        continue;
                    }

                    // Look for view() calls
                    if (preg_match_all('/view\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
                        $references['templates'] = array_merge($references['templates'], $matches[1]);
                    }

                    // Look for template assignments
                    if (preg_match_all('/[\'"]template[\'"].*?[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
                        $references['templates'] = array_merge($references['templates'], $matches[1]);
                    }
                }
            }
        }

        return $references;
    }

    /**
     * Identify unused templates.
     *
     * @param  array<array<string, mixed>>  $allTemplates
     * @param  array<string, mixed>  $referencedTemplates
     *
     * @return array<array<string, mixed>>
     */
    private function identifyUnusedTemplates(array $allTemplates, array $referencedTemplates, ?int $daysSinceModified): array
    {
        $unusedTemplates = [];
        $cutoffTime = $daysSinceModified ? time() - ($daysSinceModified * 24 * 60 * 60) : null;

        // Flatten referenced templates
        $allReferenced = [];
        foreach ($referencedTemplates as $type => $templates) {
            $allReferenced = array_merge($allReferenced, $templates);
        }

        foreach ($allTemplates as $template) {
            $templateName = $this->getTemplateName($template['relative_path']);
            $isReferenced = false;

            // Check if template is referenced
            foreach ($allReferenced as $reference) {
                if ($this->templatesMatch($templateName, $reference)) {
                    $isReferenced = true;
                    break;
                }
            }

            // Check modification time if specified
            $isOld = $cutoffTime ? $template['modified'] < $cutoffTime : false;

            // Consider template unused if not referenced and optionally old
            if (! $isReferenced && ($daysSinceModified === null || $isOld)) {
                $template['reason'] = $this->getUnusedReason($isReferenced, $isOld, $daysSinceModified);
                $unusedTemplates[] = $template;
            }
        }

        return $unusedTemplates;
    }

    /**
     * Categorize template type.
     */
    private function categorizeTemplate(string $relativePath): string
    {
        // Check for common naming patterns
        if (str_contains($relativePath, 'partial') || str_contains($relativePath, '_')) {
            return 'partial';
        }

        if (str_contains($relativePath, 'layout') || str_contains($relativePath, 'master')) {
            return 'layout';
        }

        // Check directory structure
        if (str_starts_with($relativePath, 'partials/') || str_starts_with($relativePath, '_partials/')) {
            return 'partial';
        }

        if (str_starts_with($relativePath, 'layouts/') || str_starts_with($relativePath, 'layout/')) {
            return 'layout';
        }

        return 'template';
    }

    /**
     * Get template name for comparison.
     */
    private function getTemplateName(string $path): string
    {
        // Remove extension and directory
        $name = pathinfo($path, PATHINFO_FILENAME);

        // Remove .blade suffix if present
        if (str_ends_with($name, '.blade')) {
            $name = substr($name, 0, -6);
        }

        // Remove .antlers suffix if present
        if (str_ends_with($name, '.antlers')) {
            $name = substr($name, 0, -8);
        }

        return $name;
    }

    /**
     * Check if templates match.
     */
    private function templatesMatch(string $templateName, string $reference): bool
    {
        // Direct match
        if ($templateName === $reference) {
            return true;
        }

        // Match with directory structure
        if (str_contains($reference, $templateName)) {
            return true;
        }

        // Match with dot notation (Blade style)
        $dotNotation = str_replace('/', '.', $templateName);
        if ($dotNotation === $reference) {
            return true;
        }

        return false;
    }

    /**
     * Get reason why template is considered unused.
     */
    private function getUnusedReason(bool $isReferenced, bool $isOld, ?int $daysSinceModified): string
    {
        if (! $isReferenced && $isOld) {
            return "Not referenced and not modified in {$daysSinceModified} days";
        }

        if (! $isReferenced) {
            return 'No references found in templates or code';
        }

        if ($isOld) {
            return "Not modified in {$daysSinceModified} days";
        }

        return 'Unknown';
    }

    /**
     * Generate recommendations.
     *
     * @param  array<string, mixed>  $analysis
     *
     * @return array<string>
     */
    private function generateRecommendations(array $analysis): array
    {
        $recommendations = [];

        $totalUnused = (int) $analysis['statistics']['unused_templates'] +
                      (int) $analysis['statistics']['unused_partials'] +
                      (int) $analysis['statistics']['unused_layouts'];

        if ($totalUnused > 0) {
            $recommendations[] = "Found {$totalUnused} unused templates that can be safely removed";
        }

        if ($analysis['statistics']['disk_space_saved'] > 1024 * 100) { // > 100KB
            $recommendations[] = 'Removing unused templates will free up ' . $this->formatFileSize($analysis['statistics']['disk_space_saved']) . ' of disk space';
        }

        if ($analysis['statistics']['unused_partials'] > 5) {
            $recommendations[] = 'High number of unused partials detected - consider template consolidation';
        }

        if ($analysis['statistics']['unused_layouts'] > 2) {
            $recommendations[] = 'Multiple unused layouts found - review layout hierarchy';
        }

        return $recommendations;
    }

    /**
     * Generate cleanup suggestions.
     *
     * @param  array<string, mixed>  $analysis
     *
     * @return array<string, mixed>
     */
    private function generateCleanupSuggestions(array $analysis): array
    {
        $suggestions = [];

        // Priority cleanup
        $highPriorityTemplates = [];
        foreach ([$analysis['unused_templates'], $analysis['unused_partials'], $analysis['unused_layouts']] as $templates) {
            foreach ($templates as $template) {
                if ($template['size'] > 10240) { // > 10KB
                    $highPriorityTemplates[] = $template['relative_path'];
                }
            }
        }

        if (! empty($highPriorityTemplates)) {
            $suggestions['high_priority'] = [
                'message' => 'Large unused templates that should be removed first',
                'templates' => $highPriorityTemplates,
            ];
        }

        // Safe to remove
        $safeToRemove = [];
        foreach ($analysis['unused_templates'] as $template) {
            // Templates not modified in over a year are very safe to remove
            if (time() - $template['modified'] > 365 * 24 * 60 * 60) {
                $safeToRemove[] = $template['relative_path'];
            }
        }

        if (! empty($safeToRemove)) {
            $suggestions['safe_removal'] = [
                'message' => 'Templates not modified in over a year - very safe to remove',
                'templates' => $safeToRemove,
            ];
        }

        // Backup suggestion
        if (count($analysis['unused_templates']) + count($analysis['unused_partials']) + count($analysis['unused_layouts']) > 0) {
            $suggestions['backup'] = [
                'message' => 'Create backup before removing templates',
                'command' => 'tar -czf unused_templates_backup.tar.gz ' . implode(' ', array_merge(
                    array_column($analysis['unused_templates'], 'relative_path'),
                    array_column($analysis['unused_partials'], 'relative_path'),
                    array_column($analysis['unused_layouts'], 'relative_path')
                )),
            ];
        }

        return $suggestions;
    }

    /**
     * Get cleanup status.
     *
     * @param  array<string, mixed>  $stats
     */
    private function getCleanupStatus(array $stats): string
    {
        $totalUnused = $stats['unused_templates'] + $stats['unused_partials'] + $stats['unused_layouts'];
        $unusedPercentage = $stats['total_templates'] > 0 ? ($totalUnused / $stats['total_templates']) * 100 : 0;

        if ($unusedPercentage > 30) {
            return 'needs_major_cleanup';
        }
        if ($unusedPercentage > 15) {
            return 'needs_cleanup';
        }
        if ($unusedPercentage > 5) {
            return 'minor_cleanup';
        }

        return 'clean';
    }

    /**
     * Get most common unused type.
     *
     * @param  array<string, mixed>  $stats
     */
    private function getMostCommonUnusedType(array $stats): string
    {
        $types = [
            'templates' => $stats['unused_templates'],
            'partials' => $stats['unused_partials'],
            'layouts' => $stats['unused_layouts'],
        ];

        arsort($types);

        return (string) array_key_first($types);
    }

    /**
     * Format file size.
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }
}
