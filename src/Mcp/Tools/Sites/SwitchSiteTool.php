<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Sites;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;

#[Title('Switch Default Site')]
class SwitchSiteTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.sites.switch';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Switch the default site to a different site';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('new_default')
            ->description('Handle of the site to make default')
            ->required()
            ->boolean('create_backup')
            ->description('Create backup of current configuration before switching')
            ->optional()
            ->boolean('dry_run')
            ->description('Show what would change without actually switching')
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
        $newDefault = $arguments['new_default'];
        $createBackup = $arguments['create_backup'] ?? true;
        $dryRun = $arguments['dry_run'] ?? false;

        try {
            // Check if new default site exists
            $newDefaultSite = Site::get($newDefault);
            if (! $newDefaultSite) {
                return $this->createErrorResponse("Site '{$newDefault}' not found", [
                    'available_sites' => Site::all()->map->handle()->all(),
                ])->toArray();
            }

            $currentDefault = Site::default()->handle();

            // Check if it's already the default
            if ($currentDefault === $newDefault) {
                return $this->createErrorResponse("Site '{$newDefault}' is already the default site")->toArray();
            }

            // Check if the new default site is enabled
            if (! $newDefaultSite->enabled()) {
                return $this->createErrorResponse("Cannot set disabled site '{$newDefault}' as default", [
                    'suggestion' => 'Enable the site first using statamic.sites.update',
                ])->toArray();
            }

            if ($dryRun) {
                return [
                    'dry_run' => true,
                    'current_default' => $currentDefault,
                    'new_default' => $newDefault,
                    'changes' => [
                        'config_update' => 'Would update default site in config/statamic/sites.php',
                        'cache_clear' => 'Would clear caches',
                        'backup' => $createBackup ? 'Would create configuration backup' : 'No backup',
                    ],
                    'impact_analysis' => $this->analyzeDefaultSiteChangeImpact($currentDefault, $newDefault),
                ];
            }

            $configPath = config_path('statamic/sites.php');
            $backupPath = null;

            // Create backup if requested
            if ($createBackup && file_exists($configPath)) {
                $backupPath = $configPath . '.backup.' . time();
                copy($configPath, $backupPath);
            }

            // Update sites configuration
            $sitesConfig = config('statamic.sites', []);

            // Set the new default
            $sitesConfig['default'] = $newDefault;

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

            $impactAnalysis = $this->analyzeDefaultSiteChangeImpact($currentDefault, $newDefault);

            return [
                'success' => true,
                'previous_default' => $currentDefault,
                'new_default' => $newDefault,
                'backup_created' => $backupPath ? basename($backupPath) : false,
                'config_updated' => true,
                'cache_cleared' => true,
                'impact_analysis' => $impactAnalysis,
                'next_steps' => [
                    'restart_required' => 'You may need to restart your application for changes to take effect',
                    'test_urls' => 'Test that URLs are resolving correctly for the new default site',
                    'check_content' => 'Verify that default content is showing from the correct site',
                    'update_templates' => $impactAnalysis['template_updates_needed'] ? 'Update templates that reference the old default site' : null,
                ],
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to switch default site: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Analyze the impact of changing the default site.
     *
     *
     * @return array<string, mixed>
     */
    private function analyzeDefaultSiteChangeImpact(string $oldDefault, string $newDefault): array
    {
        $impact = [
            'routing_changes' => true,
            'url_generation_affected' => true,
            'template_updates_needed' => false,
            'content_visibility_changes' => false,
            'potential_issues' => [],
        ];

        try {
            $oldSite = Site::get($oldDefault);
            $newSite = Site::get($newDefault);

            if (! $oldSite || ! $newSite) {
                $impact['potential_issues'][] = 'Could not analyze one or both sites';

                return $impact;
            }

            // Check if URLs differ significantly
            if ($oldSite->url() !== $newSite->url()) {
                $impact['potential_issues'][] = 'URL structure will change from ' . $oldSite->url() . ' to ' . $newSite->url();
            }

            // Check if locales differ
            if ($oldSite->locale() !== $newSite->locale()) {
                $impact['potential_issues'][] = 'Locale will change from ' . $oldSite->locale() . ' to ' . $newSite->locale();
                $impact['template_updates_needed'] = true;
            }

            // Check if languages differ
            if ($oldSite->lang() !== $newSite->lang()) {
                $impact['potential_issues'][] = 'Language will change from ' . $oldSite->lang() . ' to ' . $newSite->lang();
            }

            // Check collections that might have different content
            $collections = \Statamic\Facades\Collection::all();
            foreach ($collections as $collection) {
                $oldHasContent = in_array($oldDefault, $collection->sites());
                $newHasContent = in_array($newDefault, $collection->sites());

                if ($oldHasContent && ! $newHasContent) {
                    $impact['content_visibility_changes'] = true;
                    $impact['potential_issues'][] = "Collection '{$collection->handle()}' content will no longer be visible";
                } elseif (! $oldHasContent && $newHasContent) {
                    $impact['content_visibility_changes'] = true;
                    $impact['potential_issues'][] = "Collection '{$collection->handle()}' content will become visible";
                }
            }
        } catch (\Exception $e) {
            $impact['potential_issues'][] = 'Could not fully analyze impact: ' . $e->getMessage();
        }

        return $impact;
    }
}
