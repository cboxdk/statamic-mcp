<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Sites;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;

#[Title('Update Site')]
class UpdateSiteTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.sites.update';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Update an existing site configuration';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('handle')
            ->description('Handle of the site to update')
            ->required()
            ->string('name')
            ->description('Display name for the site')
            ->optional()
            ->string('url')
            ->description('Site URL or path')
            ->optional()
            ->string('locale')
            ->description('Locale code (e.g., en_US, da_DK)')
            ->optional()
            ->string('lang')
            ->description('Language code (e.g., en, da)')
            ->optional()
            ->string('direction')
            ->description('Text direction (ltr or rtl)')
            ->optional()
            ->boolean('enabled')
            ->description('Whether the site is enabled')
            ->optional()
            ->raw('attributes', [
                'type' => 'object',
                'description' => 'Additional site attributes to merge',
                'additionalProperties' => true,
            ])
            ->optional()
            ->boolean('create_backup')
            ->description('Create backup of current configuration before updating')
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
        $createBackup = $arguments['create_backup'] ?? true;

        try {
            // Check if site exists
            $currentSite = Site::get($handle);
            if (! $currentSite) {
                return $this->createErrorResponse("Site '{$handle}' not found", [
                    'available_sites' => Site::all()->map(fn ($item) => $item->handle())->all(),
                ])->toArray();
            }

            // Prevent updating default site handle
            if ($handle === Site::default()->handle() && isset($arguments['handle'])) {
                return $this->createErrorResponse('Cannot change handle of default site')->toArray();
            }

            // Get current sites config
            $sitesConfig = config('statamic.sites', []);
            $configPath = config_path('statamic/sites.php');

            // Create backup if requested
            $backupPath = null;
            if ($createBackup && file_exists($configPath)) {
                $backupPath = $configPath . '.backup.' . time();
                copy($configPath, $backupPath);
            }

            // Get current site config
            $currentConfig = $sitesConfig['sites'][$handle] ?? [];

            // Update only provided fields
            $updates = [];
            $updateFields = ['name', 'url', 'locale', 'lang', 'direction'];

            foreach ($updateFields as $field) {
                if (isset($arguments[$field])) {
                    $updates[$field] = $arguments[$field];
                    $sitesConfig['sites'][$handle][$field] = $arguments[$field];
                }
            }

            // Handle enabled status
            if (isset($arguments['enabled'])) {
                if ($arguments['enabled']) {
                    // Enable site - remove disabled attribute
                    unset($sitesConfig['sites'][$handle]['attributes']['enabled']);
                } else {
                    // Disable site
                    $sitesConfig['sites'][$handle]['attributes']['enabled'] = false;
                }
                $updates['enabled'] = $arguments['enabled'];
            }

            // Merge attributes
            if (isset($arguments['attributes']) && is_array($arguments['attributes'])) {
                $currentAttributes = $sitesConfig['sites'][$handle]['attributes'] ?? [];
                $sitesConfig['sites'][$handle]['attributes'] = array_merge($currentAttributes, $arguments['attributes']);
                $updates['attributes'] = $arguments['attributes'];
            }

            // Validate direction if provided
            if (isset($arguments['direction']) && ! in_array($arguments['direction'], ['ltr', 'rtl'])) {
                return $this->createErrorResponse('Direction must be either "ltr" or "rtl"')->toArray();
            }

            // Write updated config to file
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

            // Get updated site data
            $updatedSite = $sitesConfig['sites'][$handle];
            $updatedSite['handle'] = $handle;
            $updatedSite['enabled'] = ! isset($updatedSite['attributes']['enabled']) || $updatedSite['attributes']['enabled'];

            return [
                'success' => true,
                'site' => $updatedSite,
                'updates_applied' => $updates,
                'backup_created' => $backupPath ? basename($backupPath) : false,
                'config_updated' => true,
                'cache_cleared' => true,
                'next_steps' => [
                    'verify_config' => 'Check config/statamic/sites.php for the updated configuration',
                    'restart_required' => 'You may need to restart your application for changes to take effect',
                    'test_site' => 'Test the site functionality with the new configuration',
                ],
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to update site: ' . $e->getMessage())->toArray();
        }
    }
}
