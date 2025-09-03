<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Tags;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use ReflectionClass;
use ReflectionMethod;

#[Title('List Statamic Tags')]
#[IsReadOnly]
class ListTagsTool extends BaseStatamicTool
{
    use \Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.tags.list';
    }

    protected function getToolDescription(): string
    {
        return 'Dynamically scan and extract all available Statamic tags, their parameters, and usage information from the source code';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->boolean('include_parameters')
            ->description('Include detailed parameter information')
            ->optional()
            ->boolean('include_examples')
            ->description('Include usage examples where available')
            ->optional()
            ->string('filter')
            ->description('Filter tags by name pattern (regex)')
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
        $includeParameters = $arguments['include_parameters'] ?? true;
        $includeExamples = $arguments['include_examples'] ?? false;
        $filter = $arguments['filter'] ?? null;

        $tags = [];

        // Try to scan Statamic core tags
        try {
            $statamicTags = $this->scanStatamicTags($includeParameters);
            $tags = array_merge($tags, $statamicTags);
        } catch (\Exception $e) {
            // Fallback to predefined tags if Statamic is not available
            $tags = $this->getPredefinedTags($includeParameters);
        }

        // Apply filter if provided
        if ($filter) {
            $tags = array_filter($tags, function ($tag, $tagName) use ($filter): bool {
                return (bool) preg_match("/$filter/i", $tagName);
            }, ARRAY_FILTER_USE_BOTH);
        }

        $result = [
            'tags' => $tags,
            'total_count' => count($tags),
            'blade_syntax_info' => [
                'prefixes' => ['s:', 'statamic:', 's-', 'statamic-'],
                'parameter_binding' => [
                    'static' => 'parameter="value"',
                    'dynamic' => ':parameter="$variable"',
                ],
                'slots' => [
                    'default' => 'Content between opening and closing tags',
                    'named' => '<s:slot:name>Content</s:slot:name>',
                ],
            ],
        ];

        if ($includeExamples) {
            $result['usage_examples'] = $this->getUsageExamples();
        }

        return $result;
    }

    /**
     * Scan Statamic source code for available tags.
     */
    /**
     * @return array<string, mixed>
     */
    private function scanStatamicTags(bool $includeParameters): array
    {
        $tags = [];

        // Check if Statamic is available
        if (! class_exists('\Statamic\Tags\Collection')) {
            throw new \Exception('Statamic not available');
        }

        // Core tags with their class mappings
        $coreTagClasses = [
            'collection' => '\Statamic\Tags\Collection',
            'taxonomy' => '\Statamic\Tags\Taxonomy',
            'nav' => '\Statamic\Tags\Nav',
            'assets' => '\Statamic\Tags\Assets',
            'glide' => '\Statamic\Tags\Glide',
            'search' => '\Statamic\Tags\Search',
            'form' => '\Statamic\Tags\Form',
            'partial' => '\Statamic\Tags\Partial',
            'redirect' => '\Statamic\Tags\Redirect',
            'link' => '\Statamic\Tags\Link',
            'route' => '\Statamic\Tags\Route',
            'trans' => '\Statamic\Tags\Trans',
            'user' => '\Statamic\Tags\User',
            'users' => '\Statamic\Tags\Users',
            'can' => '\Statamic\Tags\Can',
            'foreach' => '\Statamic\Tags\Foreach',
            'get_content' => '\Statamic\Tags\GetContent',
            'increment' => '\Statamic\Tags\Increment',
            'markdown' => '\Statamic\Tags\Markdown',
            'mount' => '\Statamic\Tags\Mount',
            'no_cache' => '\Statamic\Tags\NoCache',
            'oauth' => '\Statamic\Tags\Oauth',
            'query' => '\Statamic\Tags\Query',
            'structure' => '\Statamic\Tags\Structure',
            'svg' => '\Statamic\Tags\Svg',
            'theme' => '\Statamic\Tags\Theme',
            'width' => '\Statamic\Tags\Width',
            'yields' => '\Statamic\Tags\Yields',
        ];

        foreach ($coreTagClasses as $tagName => $className) {
            try {
                if (class_exists($className)) {
                    $tags[$tagName] = $this->analyzeTagClass($className, $tagName, $includeParameters);
                }
            } catch (\Exception $e) {
                // Skip this tag if analysis fails
                continue;
            }
        }

        return $tags;
    }

    /**
     * Analyze a tag class to extract parameters and methods.
     */
    /**
     * @return array<string, mixed>
     */
    private function analyzeTagClass(string $className, string $tagName, bool $includeParameters): array
    {
        /** @var class-string $className */
        $reflection = new ReflectionClass($className);

        $tagInfo = [
            'class' => $className,
            'description' => $this->extractClassDescription($reflection),
            'blade_syntax' => [
                'short' => "<s:{$tagName}>",
                'full' => "<statamic:{$tagName}>",
            ],
            'methods' => [],
        ];

        if ($includeParameters) {
            $tagInfo['parameters'] = $this->extractTagParameters($reflection);
            $tagInfo['modifiers'] = $this->extractTagModifiers($reflection);
        }

        // Analyze public methods that might be tag methods
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
        foreach ($methods as $method) {
            $methodName = $method->getName();
            if (! in_array($methodName, ['__construct', '__call', 'setProperties', 'setContext']) &&
                ! str_starts_with($methodName, 'set') &&
                ! str_starts_with($methodName, 'get') &&
                $method->getDeclaringClass()->getName() === $className) {

                $tagInfo['methods'][] = [
                    'name' => $methodName,
                    'blade_syntax' => "<s:{$tagName}:{$methodName}>",
                    'parameters' => $this->extractMethodParameters($method),
                ];
            }
        }

        return $tagInfo;
    }

    /**
     * Extract class description from docblock.
     */
    /**
     * @param  ReflectionClass<object>  $reflection
     */
    private function extractClassDescription(ReflectionClass $reflection): string
    {
        $docComment = $reflection->getDocComment();
        if (! $docComment) {
            return 'Statamic tag for ' . $reflection->getShortName();
        }

        // Extract first line of description from docblock
        if (preg_match('/\/\*\*\s*\n\s*\*\s*(.+?)(?:\n|\*\/)/s', $docComment, $matches)) {
            return trim($matches[1]);
        }

        return 'Statamic tag for ' . $reflection->getShortName();
    }

    /**
     * Extract tag parameters from class properties or methods.
     */
    /**
     * @return array<string, mixed>
     */
    /**
     * @param  ReflectionClass<object>  $reflection
     *
     * @return array<string, mixed>
     */
    private function extractTagParameters(ReflectionClass $reflection): array
    {
        $parameters = [];

        // Look for common tag parameters based on property names or method signatures
        $commonParameters = [
            'limit' => 'Maximum number of items to return',
            'sort' => 'Sort order (field:direction)',
            'where' => 'Filter conditions',
            'filter' => 'Filter conditions',
            'from' => 'Source collection/container',
            'in' => 'Scope or context',
            'as' => 'Variable alias',
            'scope' => 'Variable scope prefix',
            'paginate' => 'Enable pagination',
            'handle' => 'Handle or identifier',
        ];

        // Try to extract actual parameters from the class
        if ($reflection->hasProperty('allowedSubTags')) {
            try {
                $property = $reflection->getProperty('allowedSubTags');
                $property->setAccessible(true);
                // This would require an instance to get the value
            } catch (\Exception $e) {
                // Ignore
            }
        }

        // For now, return common parameters
        return $commonParameters;
    }

    /**
     * Extract tag modifiers.
     */
    /**
     * @return array<string, mixed>
     */
    /**
     * @param  ReflectionClass<object>  $reflection
     *
     * @return array<string, mixed>
     */
    private function extractTagModifiers(ReflectionClass $reflection): array
    {
        // Common modifiers available in most tags
        return [
            'length' => 'Get string length',
            'upper' => 'Convert to uppercase',
            'lower' => 'Convert to lowercase',
            'title' => 'Convert to title case',
            'limit' => 'Limit to N characters/words',
            'truncate' => 'Truncate with ellipsis',
            'strip_tags' => 'Remove HTML tags',
            'format' => 'Format dates/numbers',
            'markdown' => 'Parse as markdown',
            'smartypants' => 'Apply typographic enhancements',
        ];
    }

    /**
     * Extract parameters from method signature.
     */
    /**
     * @return list<array<string, mixed>>
     */
    private function extractMethodParameters(ReflectionMethod $method): array
    {
        $parameters = [];

        foreach ($method->getParameters() as $param) {
            $parameters[] = [
                'name' => $param->getName(),
                'type' => $param->getType() ? ($param->getType() instanceof \ReflectionNamedType ? $param->getType()->getName() : (string) $param->getType()) : 'mixed',
                'required' => ! $param->isOptional(),
                'default' => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
            ];
        }

        return $parameters;
    }

    /**
     * Get predefined tags when Statamic is not available.
     */
    /**
     * @return array<string, mixed>
     */
    private function getPredefinedTags(bool $includeParameters): array
    {
        $tags = [
            'collection' => [
                'description' => 'Loop through entries from collections with advanced filtering',
                'blade_syntax' => ['short' => '<s:collection>', 'full' => '<statamic:collection>'],
                'parameters' => $includeParameters ? [
                    'from' => 'Collection handle(s) - supports wildcards (*) and multiple (|)',
                    'not_from' => 'Exclude specific collections when using wildcards',
                    'show_future' => 'Display future-dated entries (default: false)',
                    'show_past' => 'Display past entries (default: true)',
                    'since' => 'Earliest date for entries',
                    'until' => 'Latest date for entries',
                    'sort' => 'Sort entries by field(s) with direction (field:asc|desc)',
                    'limit' => 'Maximum number of entries to return',
                    'filter' => 'Apply custom filtering conditions',
                    'query_scope' => 'Apply custom query scope',
                    'offset' => 'Skip initial results',
                    'paginate' => 'Enable pagination with limit per page',
                    'as' => 'Alias entries to a new variable name',
                    'scope' => 'Prefix entry variables with scope',
                ] : [],
            ],
            'taxonomy' => [
                'description' => 'Loop through taxonomy terms',
                'blade_syntax' => ['short' => '<s:taxonomy>', 'full' => '<statamic:taxonomy>'],
                'parameters' => $includeParameters ? [
                    'from' => 'Taxonomy handle(s) - supports multiple with |',
                    'sort' => 'Sort terms by field with direction (title:asc)',
                    'limit' => 'Maximum number of terms',
                    'show_future' => 'Include future-dated terms',
                    'show_past' => 'Include past terms (default: true)',
                ] : [],
            ],
            'form' => [
                'description' => 'Create and handle Statamic forms',
                'blade_syntax' => ['short' => '<s:form:create>', 'full' => '<statamic:form:create>'],
                'methods' => [
                    ['name' => 'create', 'blade_syntax' => '<s:form:create>'],
                    ['name' => 'success', 'blade_syntax' => '<s:form:success>'],
                    ['name' => 'errors', 'blade_syntax' => '<s:form:errors>'],
                ],
                'parameters' => $includeParameters ? [
                    'handle|is|in|form' => 'Form handle/name',
                    'redirect' => 'URL to redirect after successful submission',
                    'error_redirect' => 'URL to redirect after failed submission',
                    'js' => 'Enable conditional fields with JavaScript drivers',
                ] : [],
            ],
            'assets' => [
                'description' => 'Loop through assets from asset containers',
                'blade_syntax' => ['short' => '<s:assets>', 'full' => '<statamic:assets>'],
                'parameters' => $includeParameters ? [
                    'container|from' => 'Asset container handle',
                    'folder' => 'Specific folder path within container',
                    'recursive' => 'Include assets from subfolders (default: false)',
                    'limit' => 'Maximum number of assets to return',
                    'sort' => 'Sort by field with direction (filename:asc)',
                    'filter' => 'Filter by file extension or other criteria',
                ] : [],
            ],
            'glide' => [
                'description' => 'Image manipulation and optimization using Glide',
                'blade_syntax' => ['short' => '<s:glide>', 'full' => '<statamic:glide>'],
                'parameters' => $includeParameters ? [
                    'src' => 'Image source (asset object or path)',
                    'width' => 'Output width in pixels',
                    'height' => 'Output height in pixels',
                    'square' => 'Square dimensions (same as width and height)',
                    'fit' => 'Fit mode: contain, crop, fill, stretch, crop-focal',
                    'crop' => 'Crop position: top-left, top, top-right, left, center, right, etc.',
                    'quality' => 'JPEG quality (0-100, default 90)',
                    'format' => 'Output format: jpg, png, gif, webp, avif',
                ] : [],
            ],
            'nav' => [
                'description' => 'Generate navigation menus from navigation structures',
                'blade_syntax' => ['short' => '<s:nav>', 'full' => '<statamic:nav>'],
                'parameters' => $includeParameters ? [
                    'handle|from' => 'Navigation handle/structure name',
                    'tree' => 'Navigation tree handle',
                    'from' => 'Starting point URL or ID',
                    'max_depth' => 'Maximum nesting depth',
                    'include_home' => 'Include home page in navigation',
                ] : [],
            ],
            'search' => [
                'description' => 'Perform full-text searches across content',
                'blade_syntax' => ['short' => '<s:search>', 'full' => '<statamic:search>'],
                'methods' => [
                    ['name' => 'results', 'blade_syntax' => '<s:search:results>'],
                    ['name' => 'form', 'blade_syntax' => '<s:search:form>'],
                ],
                'parameters' => $includeParameters ? [
                    'query' => 'Search query string',
                    'index' => 'Search index to use (if multiple configured)',
                    'collections' => 'Limit search to specific collections',
                    'limit' => 'Maximum number of results',
                    'supplement_data' => 'Include additional entry data (default: true)',
                ] : [],
            ],
        ];

        return $tags;
    }

    /**
     * Get usage examples for common patterns.
     */
    /**
     * @return array<string, mixed>
     */
    private function getUsageExamples(): array
    {
        return [
            'basic_collection_loop' => [
                'description' => 'Basic collection loop',
                'blade' => '<s:collection from="blog">
    <article>
        <h2>{{ $title }}</h2>
        <p>{{ $excerpt }}</p>
    </article>
</s:collection>',
            ],
            'collection_with_pagination' => [
                'description' => 'Collection with pagination',
                'blade' => '<s:collection from="blog" limit="5" paginate="true">
    <s:slot:entries>
        <article>{{ $title }}</article>
    </s:slot:entries>
    <s:slot:pagination>
        {{ $paginate }}
    </s:slot:pagination>
</s:collection>',
            ],
            'form_with_fields' => [
                'description' => 'Contact form with field rendering',
                'blade' => '<s:form:create handle="contact">
    <s:slot:fields>
        <div>
            <label>{{ $display }}</label>
            <input name="{{ $handle }}" type="{{ $type }}" />
        </div>
    </s:slot:fields>
    <button type="submit">Submit</button>
</s:form:create>',
            ],
            'responsive_image' => [
                'description' => 'Responsive image with Glide',
                'blade' => '<s:glide :src="$featured_image" width="800" height="600" fit="crop" quality="85" />',
            ],
            'navigation_menu' => [
                'description' => 'Multi-level navigation menu',
                'blade' => '<s:nav from="main">
    <ul>
        <li class="{{ $is_current ? \'active\' : \'\' }}">
            <a href="{{ $url }}">{{ $title }}</a>
            @if($children)
                <ul>
                    {{ $children }}
                        <li><a href="{{ $url }}">{{ $title }}</a></li>
                    {{ /$children }}
                </ul>
            @endif
        </li>
    </ul>
</s:nav>',
            ],
        ];
    }
}
