<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Sites;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;

#[Title('Create Site')]
class CreateSiteTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.sites.create';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Create a new site configuration';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('handle')
            ->description('Unique handle for the site')
            ->required()
            ->string('name')
            ->description('Display name for the site')
            ->required()
            ->string('url')
            ->description('Site URL or path')
            ->required()
            ->string('locale')
            ->description('Locale code (e.g., en_US, da_DK)')
            ->required()
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
                'description' => 'Additional site attributes',
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
        $name = $arguments['name'];
        $url = $arguments['url'];
        $locale = $arguments['locale'];
        $lang = $arguments['lang'] ?? substr($locale, 0, 2);
        $direction = $arguments['direction'] ?? 'ltr';
        $enabled = $arguments['enabled'] ?? true;
        $attributes = $arguments['attributes'] ?? [];

        try {
            // Check if site already exists by reading current config
            $configPath = config_path('statamic/sites.php');
            $existingSites = [];
            if (file_exists($configPath)) {
                $sitesConfig = include $configPath;
                $existingSites = array_keys($sitesConfig['sites'] ?? []);
            }

            if (in_array($handle, $existingSites)) {
                return $this->createErrorResponse("Site with handle '{$handle}' already exists", [
                    'existing_sites' => $existingSites,
                ])->toArray();
            }

            // Validate direction
            if (! in_array($direction, ['ltr', 'rtl'])) {
                return $this->createErrorResponse('Direction must be either "ltr" or "rtl"')->toArray();
            }

            // Get current sites config
            $sitesConfig = config('statamic.sites', []);

            // Add new site to config
            $sitesConfig['sites'][$handle] = [
                'name' => $name,
                'url' => $url,
                'locale' => $locale,
                'lang' => $lang,
                'direction' => $direction,
                'attributes' => $attributes,
            ];

            if (! $enabled) {
                $sitesConfig['sites'][$handle]['attributes']['enabled'] = false;
            }

            // Write config to file
            $configPath = config_path('statamic/sites.php');
            $configDir = dirname($configPath);

            // Ensure config directory exists
            if (! is_dir($configDir)) {
                mkdir($configDir, 0755, true);
            }

            $configContent = "<?php\n\nreturn " . var_export($sitesConfig, true) . ";\n";

            if (! file_put_contents($configPath, $configContent)) {
                return $this->createErrorResponse('Failed to write sites configuration file')->toArray();
            }

            // Clear caches and reload configuration
            Stache::clear();

            // Force reload of site configuration
            app('config')->set('statamic.sites', include $configPath);

            // Clear Laravel config cache
            if (app()->has('cache')) {
                app('cache')->forget('config.statamic.sites');
            }

            // Create the site object for response
            $createdSite = [
                'handle' => $handle,
                'name' => $name,
                'url' => $url,
                'locale' => $locale,
                'lang' => $lang,
                'direction' => $direction,
                'enabled' => $enabled,
                'attributes' => $attributes,
            ];

            return [
                'success' => true,
                'site' => $createdSite,
                'config_updated' => true,
                'cache_cleared' => true,
                'next_steps' => [
                    'verify_config' => 'Check config/statamic/sites.php for the new site configuration',
                    'restart_required' => 'You may need to restart your application for changes to take effect',
                    'setup_content' => 'Configure collections and global sets to use this site',
                ],
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to create site: ' . $e->getMessage())->toArray();
        }
    }
}
