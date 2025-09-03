<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Sites;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;

#[Title('Delete Site')]
class DeleteSiteTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.sites.delete';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Delete a site configuration with safety checks and cleanup options';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('handle')
            ->description('Handle of the site to delete')
            ->required()
            ->boolean('force')
            ->description('Force deletion even if site has content')
            ->optional()
            ->boolean('cleanup_content')
            ->description('Also delete all content specific to this site')
            ->optional()
            ->boolean('create_backup')
            ->description('Create backup of configuration and content before deletion')
            ->optional()
            ->boolean('dry_run')
            ->description('Show what would be deleted without actually deleting')
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
        $force = $arguments['force'] ?? false;
        $cleanupContent = $arguments['cleanup_content'] ?? false;
        $createBackup = $arguments['create_backup'] ?? true;
        $dryRun = $arguments['dry_run'] ?? false;

        try {
            // Check if site exists
            $site = Site::get($handle);
            if (! $site) {
                return $this->createErrorResponse("Site '{$handle}' not found", [
                    'available_sites' => Site::all()->map(fn ($item) => $item->handle())->all(),
                ])->toArray();
            }

            // Prevent deleting default site
            if ($handle === Site::default()->handle()) {
                return $this->createErrorResponse('Cannot delete the default site', [
                    'default_site' => Site::default()->handle(),
                    'suggestion' => 'Change the default site first, then delete this one',
                ])->toArray();
            }

            // Prevent deleting if it's the only site (unless forced)
            if (Site::all()->count() === 1 && ! $force) {
                return $this->createErrorResponse('Cannot delete the only remaining site', [
                    'use_force' => 'Set force=true to delete anyway',
                ])->toArray();
            }

            // Analyze content that would be affected
            $contentAnalysis = $this->analyzeContentForSite($handle);

            // Check if site has content and force is not set
            if ($contentAnalysis['has_content'] && ! $force && ! $dryRun) {
                return $this->createErrorResponse('Site has content and force is not set', [
                    'content_summary' => $contentAnalysis['summary'],
                    'solutions' => [
                        'use_force' => 'Set force=true to delete anyway',
                        'migrate_content' => 'Migrate content to another site first',
                        'use_dry_run' => 'Use dry_run=true to see what would be deleted',
                    ],
                ])->toArray();
            }

            if ($dryRun) {
                return [
                    'dry_run' => true,
                    'site' => [
                        'handle' => $handle,
                        'name' => $site->name(),
                        'would_be_deleted' => true,
                    ],
                    'content_analysis' => $contentAnalysis,
                    'actions' => [
                        'config_backup' => $createBackup ? 'Would create config backup' : 'No backup',
                        'content_cleanup' => $cleanupContent ? 'Would delete site-specific content' : 'Content preserved',
                        'cache_clear' => 'Would clear caches',
                    ],
                ];
            }

            $configPath = config_path('statamic/sites.php');
            $backupPath = null;

            // Create backup if requested
            if ($createBackup && file_exists($configPath)) {
                $backupPath = $configPath . '.backup.' . time();
                copy($configPath, $backupPath);
            }

            // Clean up content if requested
            $contentCleanupResult = [];
            if ($cleanupContent) {
                $contentCleanupResult = $this->cleanupSiteContent($handle);
            }

            // Remove site from config
            $sitesConfig = config('statamic.sites', []);
            unset($sitesConfig['sites'][$handle]);

            // Write updated config
            $configContent = "<?php\n\nreturn " . var_export($sitesConfig, true) . ";\n";

            if (! file_put_contents($configPath, $configContent)) {
                // Restore backup if write failed
                if ($backupPath && file_exists($backupPath)) {
                    copy($backupPath, $configPath);
                }

                return $this->createErrorResponse('Failed to write sites configuration file')->toArray();
            }

            // Clear caches
            Stache::clear();
            if (function_exists('config_clear')) {
                config_clear();
            }

            return [
                'success' => true,
                'deleted_site' => [
                    'handle' => $handle,
                    'name' => $site->name(),
                ],
                'content_analysis' => $contentAnalysis,
                'content_cleanup' => $contentCleanupResult,
                'backup_created' => $backupPath ? basename($backupPath) : false,
                'remaining_sites' => Site::all()->map(fn ($item) => $item->handle())->all(),
                'next_steps' => [
                    'verify_config' => 'Check config/statamic/sites.php for the updated configuration',
                    'restart_required' => 'You may need to restart your application for changes to take effect',
                    'check_references' => 'Review templates and content for references to the deleted site',
                ],
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to delete site: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Analyze content for a specific site.
     *
     *
     * @return array<string, mixed>
     */
    private function analyzeContentForSite(string $handle): array
    {
        $analysis = [
            'has_content' => false,
            'summary' => [],
            'details' => [],
        ];

        try {
            // Check entries
            $entryCount = 0;
            $collections = Collection::all();
            foreach ($collections as $collection) {
                if (in_array($handle, $collection->sites())) {
                    $count = $collection->queryEntries()->where('site', $handle)->count();
                    if ($count > 0) {
                        $analysis['summary'][] = "{$count} entries in {$collection->handle()} collection";
                        $analysis['details']['collections'][$collection->handle()] = $count;
                        $entryCount += $count;
                    }
                }
            }

            // Check global sets
            $globalCount = 0;
            $globalSets = GlobalSet::all();
            foreach ($globalSets as $globalSet) {
                if (in_array($handle, $globalSet->sites())) {
                    $localizedSet = $globalSet->in($handle);
                    if ($localizedSet && $localizedSet->data()->count() > 0) {
                        $analysis['summary'][] = "Global set: {$globalSet->handle()}";
                        $analysis['details']['globals'][] = $globalSet->handle();
                        $globalCount++;
                    }
                }
            }

            $analysis['has_content'] = $entryCount > 0 || $globalCount > 0;
            $analysis['totals'] = [
                'entries' => $entryCount,
                'global_sets' => $globalCount,
            ];
        } catch (\Exception $e) {
            $analysis['error'] = 'Could not analyze content: ' . $e->getMessage();
        }

        return $analysis;
    }

    /**
     * Clean up site-specific content.
     *
     *
     * @return array<string, mixed>
     */
    private function cleanupSiteContent(string $handle): array
    {
        $cleanup = [
            'entries_deleted' => 0,
            'globals_cleaned' => 0,
            'errors' => [],
        ];

        try {
            // Delete entries
            $collections = Collection::all();
            foreach ($collections as $collection) {
                if (in_array($handle, $collection->sites())) {
                    $entries = $collection->queryEntries()->where('site', $handle)->get();
                    foreach ($entries as $entry) {
                        $entry->delete();
                        $cleanup['entries_deleted']++;
                    }
                }
            }

            // Clean global sets
            $globalSets = GlobalSet::all();
            foreach ($globalSets as $globalSet) {
                if (in_array($handle, $globalSet->sites())) {
                    $localizedSet = $globalSet->in($handle);
                    if ($localizedSet) {
                        $localizedSet->delete();
                        $cleanup['globals_cleaned']++;
                    }
                }
            }
        } catch (\Exception $e) {
            $cleanup['errors'][] = 'Content cleanup error: ' . $e->getMessage();
        }

        return $cleanup;
    }
}
