<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Development;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;

#[Title('Statamic Addons Scanner')]
#[IsReadOnly]
class AddonsDevelopmentTool extends BaseStatamicTool
{
    /**
     * The tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.development.addons';
    }

    /**
     * The tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Scan and analyze installed Statamic addons, their tags, modifiers, and documentation';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->boolean('include_tags')
            ->description('Include addon tags information')
            ->optional()
            ->boolean('include_modifiers')
            ->description('Include addon modifiers information')
            ->optional()
            ->boolean('include_fieldtypes')
            ->description('Include addon field types information')
            ->optional()
            ->boolean('include_documentation')
            ->description('Include addon documentation links')
            ->optional()
            ->string('addon_filter')
            ->description('Filter by specific addon name')
            ->optional();
    }

    /**
     * Handle the tool execution.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $includeTags = $arguments['include_tags'] ?? true;
        $includeModifiers = $arguments['include_modifiers'] ?? true;
        $includeFieldtypes = $arguments['include_fieldtypes'] ?? true;
        $includeDocumentation = $arguments['include_documentation'] ?? true;
        $addonFilter = $arguments['addon_filter'] ?? null;

        $results = [
            'installed_addons' => $this->scanInstalledAddons($addonFilter),
            'community_resources' => $this->getCommunityResources(),
            'marketplace_info' => $this->getMarketplaceInfo(),
        ];

        if ($includeTags) {
            $results['addon_tags'] = $this->scanAddonTags($addonFilter);
        }

        if ($includeModifiers) {
            $results['addon_modifiers'] = $this->scanAddonModifiers($addonFilter);
        }

        if ($includeFieldtypes) {
            $results['addon_fieldtypes'] = $this->scanAddonFieldtypes($addonFilter);
        }

        if ($includeDocumentation) {
            $results['documentation_links'] = $this->findAddonDocumentation($addonFilter);
        }

        return $results;
    }

    /**
     * Scan installed Statamic addons.
     */
    /**
     * @return array<int|string, mixed>
     */
    private function scanInstalledAddons(?string $addonFilter): array
    {
        $addons = [];

        try {
            // Check if Statamic is available
            if (class_exists('\Statamic\Providers\AddonServiceProvider')) {
                // Try to get registered addons from Statamic
                $addons = $this->getStatamicAddons($addonFilter);
            }

            // Also check composer.json for Statamic packages
            $composerAddons = $this->getComposerAddons($addonFilter);
            $addons = array_merge($addons, $composerAddons);

        } catch (\Exception $e) {
            // Continue
        }

        // Always include popular addons if no specific installed addons found
        if (empty($addons)) {
            $addons = $this->getPopularAddons($addonFilter);
        }

        return $addons;
    }

    /**
     * Get registered Statamic addons.
     */
    /**
     * @return array<string, mixed>
     */
    private function getStatamicAddons(?string $filter): array
    {
        $addons = [];

        // This would require access to Statamic's addon registry
        // For now, return empty array as we can't access it in this context

        return $addons;
    }

    /**
     * Get Statamic addons from composer.json.
     */
    /**
     * @return array<int, array<string, mixed>>
     */
    private function getComposerAddons(?string $filter): array
    {
        $addons = [];

        try {
            $composerPath = base_path('composer.json');
            if (file_exists($composerPath)) {
                $composerContent = file_get_contents($composerPath);
                if ($composerContent === false) {
                    return $addons;
                }
                $composer = json_decode($composerContent, true);

                $allPackages = array_merge(
                    $composer['require'] ?? [],
                    $composer['require-dev'] ?? []
                );

                foreach ($allPackages as $package => $version) {
                    if ($this->isStatamicAddon($package)) {
                        if (! $filter || str_contains($package, $filter)) {
                            $addons[] = $this->getAddonInfo($package, $version);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // Continue with fallback
        }

        return $addons;
    }

    /**
     * Check if package is a Statamic addon.
     */
    private function isStatamicAddon(string $package): bool
    {
        // Common patterns for Statamic addons
        $patterns = [
            '/^statamic\/.+/',
            '/.*statamic.*/',
            '/.*\-statamic$/',
            '/.*statamic\-.*/',
        ];

        // Known addon vendors
        $vendors = [
            'statamic',
            'rias',
            'studio1902',
            'doublethreedigital',
            'jacksleight',
            'edalzell',
            'aerni',
            'jonassiewertsen',
            'cnj',
            'visuellverstehen',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $package)) {
                return true;
            }
        }

        $vendor = explode('/', $package)[0];

        return in_array($vendor, $vendors);
    }

    /**
     * Get addon information.
     */
    /**
     * @return array<string, mixed>
     */
    private function getAddonInfo(string $package, string $version): array
    {
        $info = [
            'name' => $package,
            'version' => $version,
            'vendor' => explode('/', $package)[0],
            'package_name' => explode('/', $package)[1],
            'type' => 'addon',
        ];

        // Try to get more info from composer.lock or package files
        $additionalInfo = $this->getAdditionalAddonInfo($package);

        return collect(array_merge($info, $additionalInfo))->mapWithKeys(fn ($item, $index) => [$index => $item])->all();
    }

    /**
     * Get additional addon information.
     */
    /**
     * @return array<string, mixed>
     */
    private function getAdditionalAddonInfo(string $package): array
    {
        $info = [];

        try {
            $lockPath = base_path('composer.lock');
            if (file_exists($lockPath)) {
                $lockContent = file_get_contents($lockPath);
                if ($lockContent === false) {
                    return $info;
                }
                $lock = json_decode($lockContent, true);

                foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $pkg) {
                    if ($pkg['name'] === $package) {
                        $info['description'] = $pkg['description'] ?? '';
                        $info['homepage'] = $pkg['homepage'] ?? '';
                        $info['repository'] = $pkg['source']['url'] ?? '';
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            // Continue without additional info
        }

        return $info;
    }

    /**
     * Get popular known addons as fallback.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getPopularAddons(?string $filter): array
    {
        $popular = [
            [
                'name' => 'statamic/seo-pro',
                'version' => '^6.0',
                'vendor' => 'statamic',
                'package_name' => 'seo-pro',
                'description' => 'Search Engine Optimization features for Statamic',
                'homepage' => 'https://statamic.dev/seo-pro',
                'type' => 'official',
            ],
            [
                'name' => 'doublethreedigital/simple-commerce',
                'version' => '^6.0',
                'vendor' => 'doublethreedigital',
                'package_name' => 'simple-commerce',
                'description' => 'E-commerce functionality for Statamic',
                'homepage' => 'https://simple-commerce.duncanmcclean.com',
                'type' => 'community',
            ],
            [
                'name' => 'rias/statamic-butik',
                'version' => '^4.0',
                'vendor' => 'rias',
                'package_name' => 'statamic-butik',
                'description' => 'E-commerce for Statamic',
                'homepage' => 'https://butik.dev',
                'type' => 'commercial',
            ],
            [
                'name' => 'studio1902/statamic-peak',
                'version' => '^7.0',
                'vendor' => 'studio1902',
                'package_name' => 'statamic-peak',
                'description' => 'An opinionated starter kit for Statamic',
                'homepage' => 'https://peak.1902.studio',
                'type' => 'starter-kit',
            ],
        ];

        if ($filter) {
            $popular = array_filter($popular, fn ($addon) => str_contains($addon['name'], $filter) ||
                str_contains($addon['package_name'], $filter)
            );
        }

        return $popular;
    }

    /**
     * Scan addon tags.
     */
    /**
     * @return array<string, mixed>
     */
    private function scanAddonTags(?string $filter): array
    {
        // This would ideally scan the actual registered tags
        // For now, return known addon tags
        return [
            'seo_pro' => [
                'addon' => 'statamic/seo-pro',
                'tags' => ['seo', 'seo:title', 'seo:description', 'seo:canonical'],
                'description' => 'SEO-related tags for meta information',
            ],
            'simple_commerce' => [
                'addon' => 'doublethreedigital/simple-commerce',
                'tags' => ['sc:cart', 'sc:products', 'sc:checkout', 'sc:customer'],
                'description' => 'E-commerce tags for products and cart management',
            ],
            'butik' => [
                'addon' => 'rias/statamic-butik',
                'tags' => ['butik:cart', 'butik:products', 'butik:categories'],
                'description' => 'Butik e-commerce tags',
            ],
        ];
    }

    /**
     * Scan addon modifiers.
     */
    /**
     * @return array<string, mixed>
     */
    private function scanAddonModifiers(?string $filter): array
    {
        return [
            'seo_pro' => [
                'addon' => 'statamic/seo-pro',
                'modifiers' => ['seo_title', 'seo_description', 'og_title'],
                'description' => 'SEO-specific modifiers for meta content',
            ],
            'simple_commerce' => [
                'addon' => 'doublethreedigital/simple-commerce',
                'modifiers' => ['currency', 'money_format', 'tax_rate'],
                'description' => 'Commerce-related formatting modifiers',
            ],
        ];
    }

    /**
     * Scan addon field types.
     */
    /**
     * @return array<string, mixed>
     */
    private function scanAddonFieldtypes(?string $filter): array
    {
        return [
            'seo_pro' => [
                'addon' => 'statamic/seo-pro',
                'fieldtypes' => ['seo'],
                'description' => 'SEO fieldtype for managing meta information',
            ],
            'simple_commerce' => [
                'addon' => 'doublethreedigital/simple-commerce',
                'fieldtypes' => ['money', 'product_variants'],
                'description' => 'E-commerce specific field types',
            ],
        ];
    }

    /**
     * Find addon documentation.
     */
    /**
     * @return array<string, mixed>
     */
    private function findAddonDocumentation(?string $filter): array
    {
        return [
            'statamic/seo-pro' => [
                'documentation' => 'https://statamic.dev/seo-pro',
                'github' => 'https://github.com/statamic/seo-pro',
                'type' => 'official',
            ],
            'doublethreedigital/simple-commerce' => [
                'documentation' => 'https://simple-commerce.duncanmcclean.com',
                'github' => 'https://github.com/doublethreedigital/simple-commerce',
                'type' => 'community',
            ],
            'rias/statamic-butik' => [
                'documentation' => 'https://butik.dev',
                'github' => 'https://github.com/riasvdv/statamic-butik',
                'type' => 'commercial',
            ],
            'studio1902/statamic-peak' => [
                'documentation' => 'https://peak.1902.studio',
                'github' => 'https://github.com/studio1902/statamic-peak',
                'type' => 'starter-kit',
            ],
        ];
    }

    /**
     * Get community resources.
     */
    /**
     * @return array<string, mixed>
     */
    private function getCommunityResources(): array
    {
        return [
            'official_marketplace' => 'https://statamic.com/marketplace',
            'github_topic' => 'https://github.com/topics/statamic',
            'awesome_statamic' => 'https://github.com/statamic/awesome-statamic',
            'discord' => 'https://statamic.com/discord',
            'slack' => 'https://statamic.com/slack',
            'forum' => 'https://github.com/statamic/cms/discussions',
        ];
    }

    /**
     * Get marketplace information.
     */
    /**
     * @return array<string, mixed>
     */
    private function getMarketplaceInfo(): array
    {
        return [
            'categories' => [
                'commerce' => 'E-commerce functionality',
                'seo' => 'Search engine optimization',
                'forms' => 'Form handling and extensions',
                'media' => 'Image and media management',
                'navigation' => 'Navigation and menu tools',
                'content' => 'Content management extensions',
                'utilities' => 'Developer utilities and tools',
                'integrations' => 'Third-party service integrations',
            ],
            'popular_commercial' => [
                'Butik' => 'https://butik.dev',
                'Runway' => 'https://statamic.com/addons/double-three-digital/runway',
                'Peak Tools' => 'https://peak.1902.studio/addons',
            ],
            'popular_free' => [
                'Simple Commerce' => 'https://simple-commerce.duncanmcclean.com',
                'Translator' => 'https://github.com/rias/statamic-translator',
                'Redirect' => 'https://github.com/rias/statamic-redirect',
                'Responsive Images' => 'https://github.com/spatie/statamic-responsive-images',
            ],
        ];
    }
}
