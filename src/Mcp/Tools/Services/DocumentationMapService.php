<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Services;

class DocumentationMapService
{
    /**
     * Get comprehensive documentation map.
     */
    /**
     * @return array<string, mixed>
     */
    public function getDocumentationMap(?string $section): array
    {
        $dynamicDocs = $this->fetchDynamicDocumentationIndex();

        if (! empty($dynamicDocs)) {
            $docMap = array_merge($this->getStaticDocumentationMap(), $dynamicDocs);
        } else {
            $docMap = $this->getStaticDocumentationMap();
        }

        if ($section) {
            $docMap = array_filter($docMap, fn ($doc) => $doc['section'] === $section);
        }

        return $docMap;
    }

    /**
     * Fetch dynamic documentation index from Statamic.dev
     */
    /**
     * @return array<string, mixed>
     */
    private function fetchDynamicDocumentationIndex(): array
    {
        $dynamicDocs = [];

        try {
            $sitemapUrls = [
                'https://statamic.dev/sitemap.xml',
                'https://statamic.dev/api/content',
            ];

            foreach ($sitemapUrls as $url) {
                $content = $this->fetchUrl($url);
                if ($content) {
                    $parsed = $this->parseSitemap($content);
                    if (! empty($parsed)) {
                        $dynamicDocs = array_merge($dynamicDocs, $parsed);
                        break;
                    }
                }
            }

            $addonDocs = $this->discoverAddonDocumentation();
            $dynamicDocs = array_merge($dynamicDocs, $addonDocs);

        } catch (\Exception $e) {
            // Continue with static map if dynamic fetch fails
        }

        return $dynamicDocs;
    }

    /**
     * Parse sitemap XML to extract documentation URLs
     */
    /**
     * @return array<string, mixed>
     */
    private function parseSitemap(string $xmlContent): array
    {
        $docs = [];

        try {
            $xml = simplexml_load_string($xmlContent);

            if ($xml && isset($xml->url)) {
                foreach ($xml->url as $url) {
                    $location = (string) $url->loc;

                    if (preg_match('#https://statamic\.dev/(.+)#', $location, $matches)) {
                        $path = $matches[1];
                        $docInfo = $this->inferDocumentationInfo($path, $location);

                        if ($docInfo) {
                            $docs[$location] = $docInfo;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // Return empty array if parsing fails
        }

        return $docs;
    }

    /**
     * Infer documentation information from URL path
     */
    private function inferDocumentationInfo(string $path, string $url): ?array
    {
        $skipPatterns = [
            'blog', 'screencasts', 'marketplace', 'discord', 'github',
        ];

        foreach ($skipPatterns as $pattern) {
            if (str_contains($path, $pattern)) {
                return null;
            }
        }

        $section = 'core';
        $tags = [];
        $keywords = [];

        if (str_starts_with($path, 'tags/')) {
            $section = 'tags';
            $tagName = str_replace('tags/', '', $path);
            $tags[] = 'tag';  // Will be converted to associative
            $tags[] = $tagName;
            $keywords[] = $tagName . ' tag';
        } elseif (str_starts_with($path, 'modifiers/')) {
            $section = 'modifiers';
            $modifierName = str_replace('modifiers/', '', $path);
            $tags[] = 'modifier';  // Will be converted to associative
            $tags[] = $modifierName;
            $keywords[] = $modifierName . ' modifier';
        } elseif (str_starts_with($path, 'fieldtypes/')) {
            $section = 'fieldtypes';
            $fieldType = str_replace('fieldtypes/', '', $path);
            $tags[] = 'fieldtype';  // Will be converted to associative
            $tags[] = $fieldType;
            $keywords[] = $fieldType . ' field';
        } elseif (str_contains($path, 'extending')) {
            $section = 'development';
            $tags[] = 'extending';  // Will be converted to associative
            $tags[] = 'development';  // Will be converted to associative
        } elseif (str_contains($path, 'rest-api')) {
            $section = 'rest-api';
            $tags[] = 'api';  // Will be converted to associative
            $tags[] = 'rest';  // Will be converted to associative
        }

        $title = ucwords(str_replace(['/', '-', '_'], ' ', $path));

        return [
            'title' => $title,
            'section' => $section,
            'summary' => "Documentation for {$title}",
            'tags' => $tags,
            'keywords' => $keywords,
        ];
    }

    /**
     * Discover addon documentation from common sources.
     */
    /**
     * @return array<string, mixed>
     */
    private function discoverAddonDocumentation(): array
    {
        $addonDocs = [];

        $popularAddons = [
            'peak' => [
                'base_url' => 'https://peak.1902.studio',
                'sections' => ['docs', 'features', 'installation'],
            ],
            'butik' => [
                'base_url' => 'https://butik.dev',
                'sections' => ['docs', 'configuration'],
            ],
            'runway' => [
                'base_url' => 'https://runway.duncanmcclean.com',
                'sections' => ['docs'],
            ],
        ];

        foreach ($popularAddons as $addon => $config) {
            $urls = $this->generateAddonUrls($addon, $config);
            foreach ($urls as $url) {
                $addonDocs[$url] = [
                    'title' => ucfirst($addon) . ' Documentation',
                    'section' => 'addons',
                    'summary' => "Documentation for the {$addon} Statamic addon",
                    'tags' => ['addon', $addon, 'third-party'],
                    'keywords' => [$addon, 'addon', 'extension'],
                ];
            }
        }

        return $addonDocs;
    }

    /**
     * Generate URLs for addon documentation.
     *
     * @return array<string, mixed>
     */
    private function generateAddonUrls(string $addon, array $config): array
    {
        $urls = [];
        $baseUrl = $config['base_url'];

        foreach ($config['sections'] as $section) {
            $urls[$section] = "{$baseUrl}/{$section}";
        }

        return $urls;
    }

    /**
     * Fetch URL content with cURL.
     */
    private function fetchUrl(string $url): ?string
    {
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => 'Statamic MCP Documentation Fetcher',
                CURLOPT_SSL_VERIFYPEER => false,
            ]);

            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && $content !== false) {
                return $content;
            }
        } catch (\Exception $e) {
            // Return null if fetch fails
        }

        return null;
    }

    /**
     * Get static documentation map as fallback
     */
    /**
     * @return array<string, mixed>
     */
    private function getStaticDocumentationMap(): array
    {
        return [
            // Core concepts
            'https://statamic.dev/collections' => [
                'title' => 'Collections',
                'section' => 'core',
                'summary' => 'Collections are containers that hold related entries. Think of them as content types.',
                'tags' => ['collections', 'entries', 'content', 'structure'],
                'keywords' => ['collection', 'entries', 'content types', 'structured data'],
            ],
            'https://statamic.dev/blueprints' => [
                'title' => 'Blueprints',
                'section' => 'core',
                'summary' => 'Blueprints define the fields and content structure for entries, terms, assets, and users.',
                'tags' => ['blueprints', 'fields', 'structure', 'schema'],
                'keywords' => ['blueprint', 'fields', 'fieldsets', 'content structure'],
            ],
            'https://statamic.dev/fieldsets' => [
                'title' => 'Fieldsets',
                'section' => 'core',
                'summary' => 'Fieldsets are reusable groups of fields that can be imported into blueprints.',
                'tags' => ['fieldsets', 'fields', 'reusable', 'blueprints'],
                'keywords' => ['fieldset', 'reusable fields', 'field groups'],
            ],
            'https://statamic.dev/taxonomies' => [
                'title' => 'Taxonomies',
                'section' => 'core',
                'summary' => 'Taxonomies are systems of classifying data around a set of unique characteristics.',
                'tags' => ['taxonomies', 'terms', 'classification', 'tagging'],
                'keywords' => ['taxonomy', 'terms', 'categories', 'tags'],
            ],
            'https://statamic.dev/assets' => [
                'title' => 'Assets',
                'section' => 'core',
                'summary' => 'Assets are files like images, videos, documents that can be managed and referenced.',
                'tags' => ['assets', 'files', 'images', 'media'],
                'keywords' => ['assets', 'files', 'images', 'media', 'uploads'],
            ],

            // Templating
            'https://statamic.dev/antlers' => [
                'title' => 'Antlers Template Language',
                'section' => 'templating',
                'summary' => 'Antlers is Statamic\'s simple but powerful templating language.',
                'tags' => ['antlers', 'templating', 'templates'],
                'keywords' => ['antlers', 'templates', 'templating', 'variables', 'tags'],
            ],
            'https://statamic.dev/blade' => [
                'title' => 'Blade Templates',
                'section' => 'templating',
                'summary' => 'Use Laravel\'s Blade templating engine with Statamic components and tags.',
                'tags' => ['blade', 'templating', 'laravel'],
                'keywords' => ['blade', 'templates', 'laravel', 'components'],
            ],
            'https://statamic.dev/views' => [
                'title' => 'Views & Layouts',
                'section' => 'templating',
                'summary' => 'How templates, layouts, and partials work together in Statamic.',
                'tags' => ['views', 'layouts', 'templates'],
                'keywords' => ['views', 'layouts', 'templates', 'partials'],
            ],

            // Field Types
            'https://statamic.dev/fieldtypes/bard' => [
                'title' => 'Bard Fieldtype',
                'section' => 'fieldtypes',
                'summary' => 'A rich text editor fieldtype with support for sets, marks, and custom blocks.',
                'tags' => ['fieldtype', 'bard', 'rich-text', 'editor'],
                'keywords' => ['bard', 'rich text', 'editor', 'sets', 'blocks'],
            ],
            'https://statamic.dev/fieldtypes/replicator' => [
                'title' => 'Replicator Fieldtype',
                'section' => 'fieldtypes',
                'summary' => 'Create repeatable sets of fields for flexible content structures.',
                'tags' => ['fieldtype', 'replicator', 'repeatable', 'flexible'],
                'keywords' => ['replicator', 'sets', 'repeatable', 'flexible content'],
            ],
            'https://statamic.dev/fieldtypes/grid' => [
                'title' => 'Grid Fieldtype',
                'section' => 'fieldtypes',
                'summary' => 'Create a table-like structure with defined columns and unlimited rows.',
                'tags' => ['fieldtype', 'grid', 'table', 'rows'],
                'keywords' => ['grid', 'table', 'columns', 'rows'],
            ],
            'https://statamic.dev/fieldtypes/assets' => [
                'title' => 'Assets Fieldtype',
                'section' => 'fieldtypes',
                'summary' => 'Select and manage files from asset containers.',
                'tags' => ['fieldtype', 'assets', 'files', 'media'],
                'keywords' => ['assets', 'files', 'images', 'media', 'uploads'],
            ],

            // Popular Tags
            'https://statamic.dev/tags/collection' => [
                'title' => 'Collection Tag',
                'section' => 'tags',
                'summary' => 'Fetch and display entries from a collection.',
                'tags' => ['tag', 'collection', 'entries'],
                'keywords' => ['collection tag', 'entries', 'loop', 'fetch'],
            ],
            'https://statamic.dev/tags/taxonomy' => [
                'title' => 'Taxonomy Tag',
                'section' => 'tags',
                'summary' => 'Fetch and display terms from a taxonomy.',
                'tags' => ['tag', 'taxonomy', 'terms'],
                'keywords' => ['taxonomy tag', 'terms', 'categories', 'classification'],
            ],
            'https://statamic.dev/tags/nav' => [
                'title' => 'Nav Tag',
                'section' => 'tags',
                'summary' => 'Generate navigation menus from structures or collections.',
                'tags' => ['tag', 'nav', 'navigation', 'menu'],
                'keywords' => ['nav tag', 'navigation', 'menu', 'structure'],
            ],
            'https://statamic.dev/tags/form' => [
                'title' => 'Form Tag',
                'section' => 'tags',
                'summary' => 'Render forms and handle form submissions.',
                'tags' => ['tag', 'form', 'submission'],
                'keywords' => ['form tag', 'forms', 'submissions', 'validation'],
            ],
            'https://statamic.dev/tags/glide' => [
                'title' => 'Glide Tag',
                'section' => 'tags',
                'summary' => 'Manipulate and transform images on the fly.',
                'tags' => ['tag', 'glide', 'images', 'transformation'],
                'keywords' => ['glide tag', 'images', 'resize', 'crop', 'transform'],
            ],

            // Modifiers
            'https://statamic.dev/modifiers' => [
                'title' => 'Modifiers Overview',
                'section' => 'modifiers',
                'summary' => 'Modifiers allow you to manipulate data in your templates.',
                'tags' => ['modifiers', 'filters', 'data'],
                'keywords' => ['modifiers', 'filters', 'pipes', 'data manipulation'],
            ],
            'https://statamic.dev/modifiers/format' => [
                'title' => 'Format Modifier',
                'section' => 'modifiers',
                'summary' => 'Format dates, numbers, and strings.',
                'tags' => ['modifier', 'format', 'dates'],
                'keywords' => ['format', 'date format', 'number format'],
            ],
            'https://statamic.dev/modifiers/markdown' => [
                'title' => 'Markdown Modifier',
                'section' => 'modifiers',
                'summary' => 'Convert Markdown text to HTML.',
                'tags' => ['modifier', 'markdown', 'html'],
                'keywords' => ['markdown', 'html', 'conversion'],
            ],

            // Development
            'https://statamic.dev/extending' => [
                'title' => 'Extending Statamic',
                'section' => 'development',
                'summary' => 'Learn how to extend Statamic with addons, fieldtypes, tags, and more.',
                'tags' => ['extending', 'development', 'addons'],
                'keywords' => ['extending', 'addons', 'development', 'custom'],
            ],
            'https://statamic.dev/extending/addons' => [
                'title' => 'Creating Addons',
                'section' => 'development',
                'summary' => 'Build and distribute Statamic addons.',
                'tags' => ['addons', 'development', 'extending'],
                'keywords' => ['addons', 'packages', 'development', 'creation'],
            ],
            'https://statamic.dev/extending/fieldtypes' => [
                'title' => 'Custom Fieldtypes',
                'section' => 'development',
                'summary' => 'Create custom field types for unique data requirements.',
                'tags' => ['fieldtypes', 'development', 'custom'],
                'keywords' => ['custom fieldtypes', 'development', 'fields'],
            ],

            // CLI
            'https://statamic.dev/cli' => [
                'title' => 'Command Line Interface',
                'section' => 'cli',
                'summary' => 'Use Statamic\'s CLI commands for installation, updates, and maintenance.',
                'tags' => ['cli', 'commands', 'terminal'],
                'keywords' => ['cli', 'commands', 'terminal', 'artisan'],
            ],

            // REST API
            'https://statamic.dev/rest-api' => [
                'title' => 'REST API',
                'section' => 'rest-api',
                'summary' => 'Access your Statamic content via REST API endpoints.',
                'tags' => ['api', 'rest', 'endpoints'],
                'keywords' => ['rest api', 'endpoints', 'json', 'content api'],
            ],
        ];
    }
}
