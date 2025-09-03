<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\FieldTypes;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Fieldtype;

#[Title('Statamic Field Types Explorer')]
#[IsReadOnly]
class ListFieldTypesTool extends BaseStatamicTool
{
    use \Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;

    /**
     * The tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.fieldtypes.list';
    }

    /**
     * The tool description.
     */
    protected function getToolDescription(): string
    {
        return 'List all available Statamic field types with their configuration options and expected data structures';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('type')
            ->description('Specific field type to get details for (optional)')
            ->optional()
            ->boolean('include_examples')
            ->description('Include example configurations and data structures')
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
        $specificType = $arguments['type'] ?? null;
        $includeExamples = $arguments['include_examples'] ?? true;

        if ($specificType) {
            $fieldTypeData = $this->getFieldTypeDetails($specificType, $includeExamples);
            if (! $fieldTypeData) {
                throw new \InvalidArgumentException("Field type '{$specificType}' not found");
            }

            return [
                'field_type' => $fieldTypeData,
            ];
        }

        $fieldTypes = $this->getAllFieldTypes();
        $categorized = $this->categorizeFieldTypes($fieldTypes, $includeExamples);

        return [
            'field_types' => $categorized,
            'total_count' => count($fieldTypes),
            'categories' => array_keys($categorized),
        ];
    }

    /**
     * Get all available field types.
     */
    /**
     * @return array<string, mixed>
     */
    private function getAllFieldTypes(): array
    {
        $fieldTypes = [];

        try {
            // Check if Fieldtype facade is available before using it
            if (class_exists('Statamic\Facades\Fieldtype')) {
                $registeredTypes = Fieldtype::all();

                foreach ($registeredTypes as $handle => $fieldtype) {
                    $fieldTypes[$handle] = $fieldtype;
                }
            } else {
                throw new \Exception('Fieldtype facade not available');
            }
        } catch (\Exception $e) {
            // Fallback to manual list if Statamic is not fully initialized
            $fieldTypes = $this->getManualFieldTypesList();
        }

        return $fieldTypes;
    }

    /**
     * Get field type details.
     *
     * @return array<string, mixed>|null
     */
    private function getFieldTypeDetails(string $type, bool $includeExamples = true): ?array
    {
        try {
            // Check if Fieldtype facade is available before using it
            if (class_exists('Statamic\Facades\Fieldtype')) {
                $fieldtype = Fieldtype::find($type);
                if (! $fieldtype) {
                    return $this->getManualFieldTypeDetails($type, $includeExamples);
                }

                $details = [
                    'handle' => $type,
                    'title' => $fieldtype->title(),
                    'category' => $this->getCategoryForFieldType($type),
                    'description' => $this->getFieldTypeDescription($type),
                    'configurable' => $fieldtype->configFields()->all(),
                    'indexable' => $fieldtype->isSearchable(),
                    'filterable' => $fieldtype->isFilterable(),
                    'sortable' => $fieldtype->isSortable(),
                    'localizable' => $fieldtype->isLocalizable(),
                    'selectable' => $fieldtype->isSelectable(),
                ];

                if ($includeExamples) {
                    $details['examples'] = $this->getFieldTypeExamples($type);
                }

                return $details;
            } else {
                throw new \Exception('Fieldtype facade not available');
            }
        } catch (\Exception $e) {
            return $this->getManualFieldTypeDetails($type, $includeExamples);
        }
    }

    /**
     * Categorize field types.
     *
     * @param  array<string, mixed>  $fieldTypes
     *
     * @return array<string, mixed>
     */
    private function categorizeFieldTypes(array $fieldTypes, bool $includeExamples = true): array
    {
        $categories = [
            'text' => [],
            'rich_content' => [],
            'media' => [],
            'relationship' => [],
            'structured_data' => [],
            'special' => [],
            'hidden' => [],
        ];

        foreach ($fieldTypes as $handle => $fieldtype) {
            $category = $this->getCategoryForFieldType($handle);
            $details = $this->getFieldTypeDetails($handle, $includeExamples);

            if ($details) {
                $categories[$category][$handle] = $details;
            }
        }

        return array_filter($categories);
    }

    /**
     * Get category for a field type.
     */
    private function getCategoryForFieldType(string $type): string
    {
        $categories = [
            'text' => ['text', 'textarea', 'markdown', 'code'],
            'rich_content' => ['bard', 'redactor'],
            'media' => ['assets', 'video'],
            'relationship' => ['entries', 'taxonomy', 'users', 'collections', 'sites'],
            'structured_data' => ['replicator', 'grid', 'group', 'yaml', 'array'],
            'special' => ['range', 'date', 'time', 'color', 'toggle', 'select', 'radio', 'checkboxes', 'button_group'],
            'hidden' => ['hidden', 'slug', 'template'],
        ];

        foreach ($categories as $category => $types) {
            if (in_array($type, $types)) {
                return $category;
            }
        }

        return 'special';
    }

    /**
     * Get field type description.
     */
    private function getFieldTypeDescription(string $type): string
    {
        $descriptions = [
            'text' => 'Single line text input',
            'textarea' => 'Multi-line text input',
            'markdown' => 'Markdown text editor',
            'bard' => 'Rich text editor with customizable blocks',
            'redactor' => 'WYSIWYG rich text editor',
            'assets' => 'File and image selection',
            'entries' => 'Entry relationships',
            'taxonomy' => 'Taxonomy term relationships',
            'users' => 'User relationships',
            'collections' => 'Collection selection',
            'replicator' => 'Repeatable set-based content blocks',
            'grid' => 'Table-like repeatable fields',
            'group' => 'Field grouping container',
            'range' => 'Numeric range slider',
            'date' => 'Date picker',
            'time' => 'Time picker',
            'color' => 'Color picker',
            'toggle' => 'Boolean on/off switch',
            'select' => 'Dropdown selection',
            'radio' => 'Radio button selection',
            'checkboxes' => 'Multiple checkbox selection',
            'button_group' => 'Button group selection',
            'yaml' => 'YAML data input',
            'array' => 'Key-value array input',
            'hidden' => 'Hidden field',
            'slug' => 'URL slug generator',
            'template' => 'Template selection',
            'sites' => 'Site selection',
            'video' => 'Video embed field',
            'code' => 'Code editor with syntax highlighting',
        ];

        return $descriptions[$type] ?? 'Custom field type';
    }

    /**
     * Get field type examples.
     */
    /**
     * @return array<string, mixed>
     */
    private function getFieldTypeExamples(string $type): array
    {
        $examples = [
            'text' => [
                'config' => [
                    'display' => 'Title',
                    'type' => 'text',
                    'required' => true,
                    'validate' => 'required|string|max:255',
                    'character_limit' => 255,
                    'input_type' => 'text',
                    'placeholder' => 'Enter title...',
                ],
                'data' => 'My Blog Post Title',
                'usage' => '{{ title }}',
            ],
            'textarea' => [
                'config' => [
                    'display' => 'Description',
                    'type' => 'textarea',
                    'rows' => 4,
                    'character_limit' => 500,
                ],
                'data' => 'This is a multi-line description...',
                'usage' => '{{ description }}',
            ],
            'bard' => [
                'config' => [
                    'display' => 'Content',
                    'type' => 'bard',
                    'sets' => [
                        'text' => [
                            'display' => 'Text',
                            'fields' => [
                                ['handle' => 'text', 'field' => ['type' => 'textarea']],
                            ],
                        ],
                        'image' => [
                            'display' => 'Image',
                            'fields' => [
                                ['handle' => 'image', 'field' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 1]],
                                ['handle' => 'alt', 'field' => ['type' => 'text']],
                            ],
                        ],
                    ],
                ],
                'data' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello world']]],
                    ['type' => 'set', 'attrs' => ['values' => ['type' => 'image', 'image' => 'image.jpg', 'alt' => 'Sample image']]],
                ],
                'usage' => '{{ content }}',
            ],
            'assets' => [
                'config' => [
                    'display' => 'Featured Image',
                    'type' => 'assets',
                    'container' => 'assets',
                    'max_files' => 1,
                    'mode' => 'grid',
                    'restrict' => false,
                    'allow_uploads' => true,
                ],
                'data' => 'images/hero-image.jpg',
                'usage' => '{{ featured_image }}',
            ],
            'entries' => [
                'config' => [
                    'display' => 'Related Posts',
                    'type' => 'entries',
                    'collections' => ['blog'],
                    'max_items' => 3,
                    'mode' => 'select',
                    'create' => false,
                ],
                'data' => ['blog-post-1', 'blog-post-2'],
                'usage' => '{{ related_posts }}{{ title }}{{ /related_posts }}',
            ],
            'replicator' => [
                'config' => [
                    'display' => 'Content Blocks',
                    'type' => 'replicator',
                    'sets' => [
                        'text_block' => [
                            'display' => 'Text Block',
                            'fields' => [
                                ['handle' => 'text', 'field' => ['type' => 'textarea']],
                            ],
                        ],
                        'image_block' => [
                            'display' => 'Image Block',
                            'fields' => [
                                ['handle' => 'image', 'field' => ['type' => 'assets', 'container' => 'assets']],
                                ['handle' => 'caption', 'field' => ['type' => 'text']],
                            ],
                        ],
                    ],
                ],
                'data' => [
                    ['type' => 'text_block', 'text' => 'This is some text content.'],
                    ['type' => 'image_block', 'image' => 'image.jpg', 'caption' => 'Sample image'],
                ],
                'usage' => '{{ content_blocks }}{{ if type == "text_block" }}{{ text }}{{ /if }}{{ /content_blocks }}',
            ],
            'select' => [
                'config' => [
                    'display' => 'Category',
                    'type' => 'select',
                    'options' => [
                        'news' => 'News',
                        'tutorial' => 'Tutorial',
                        'review' => 'Review',
                    ],
                    'multiple' => false,
                    'clearable' => true,
                    'searchable' => true,
                    'placeholder' => 'Choose a category...',
                ],
                'data' => 'news',
                'usage' => '{{ category }}',
            ],
            'toggle' => [
                'config' => [
                    'display' => 'Featured',
                    'type' => 'toggle',
                    'default' => false,
                ],
                'data' => true,
                'usage' => '{{ if featured }}Featured{{ /if }}',
            ],
            'date' => [
                'config' => [
                    'display' => 'Publish Date',
                    'type' => 'date',
                    'mode' => 'single',
                    'time_enabled' => false,
                    'earliest_date' => '2020-01-01',
                    'format' => 'Y-m-d',
                ],
                'data' => '2024-01-15',
                'usage' => '{{ publish_date format="F j, Y" }}',
            ],
        ];

        return $examples[$type] ?? [
            'config' => ['type' => $type],
            'data' => null,
            'usage' => '{{ field_handle }}',
        ];
    }

    /**
     * Manual field types list for fallback.
     */
    /**
     * @return array<string, mixed>
     */
    private function getManualFieldTypesList(): array
    {
        return [
            'text' => 'text',
            'textarea' => 'textarea',
            'markdown' => 'markdown',
            'bard' => 'bard',
            'redactor' => 'redactor',
            'assets' => 'assets',
            'entries' => 'entries',
            'taxonomy' => 'taxonomy',
            'users' => 'users',
            'collections' => 'collections',
            'replicator' => 'replicator',
            'grid' => 'grid',
            'group' => 'group',
            'range' => 'range',
            'date' => 'date',
            'time' => 'time',
            'color' => 'color',
            'toggle' => 'toggle',
            'select' => 'select',
            'radio' => 'radio',
            'checkboxes' => 'checkboxes',
            'button_group' => 'button_group',
            'yaml' => 'yaml',
            'array' => 'array',
            'hidden' => 'hidden',
            'slug' => 'slug',
            'template' => 'template',
            'sites' => 'sites',
            'video' => 'video',
            'code' => 'code',
        ];
    }

    /**
     * Manual field type details for fallback.
     *
     * @return array<string, mixed>|null
     */
    private function getManualFieldTypeDetails(string $type, bool $includeExamples = true): ?array
    {
        $manualTypes = $this->getManualFieldTypesList();

        if (! isset($manualTypes[$type])) {
            return null;
        }

        $details = [
            'handle' => $type,
            'title' => ucfirst(str_replace('_', ' ', $type)),
            'category' => $this->getCategoryForFieldType($type),
            'description' => $this->getFieldTypeDescription($type),
            'configurable' => $this->getManualConfigFields($type),
            'indexable' => $this->isIndexable($type),
            'filterable' => $this->isFilterable($type),
            'sortable' => $this->isSortable($type),
            'localizable' => true,
            'selectable' => true,
        ];

        if ($includeExamples) {
            $details['examples'] = $this->getFieldTypeExamples($type);
        }

        return $details;
    }

    /**
     * Get manual config fields for field type.
     */
    /**
     * @return array<string, mixed>
     */
    private function getManualConfigFields(string $type): array
    {
        $baseConfig = [
            'display' => ['type' => 'text', 'required' => true],
            'instructions' => ['type' => 'textarea'],
            'required' => ['type' => 'toggle'],
            'validate' => ['type' => 'text'],
        ];

        $typeSpecificConfig = [
            'text' => [
                'character_limit' => ['type' => 'integer'],
                'input_type' => ['type' => 'select', 'options' => ['text', 'email', 'password', 'url']],
                'placeholder' => ['type' => 'text'],
            ],
            'textarea' => [
                'character_limit' => ['type' => 'integer'],
                'rows' => ['type' => 'integer'],
                'placeholder' => ['type' => 'text'],
            ],
            'assets' => [
                'container' => ['type' => 'text'],
                'max_files' => ['type' => 'integer'],
                'mode' => ['type' => 'select', 'options' => ['grid', 'list']],
                'allow_uploads' => ['type' => 'toggle'],
                'restrict' => ['type' => 'toggle'],
            ],
            'entries' => [
                'collections' => ['type' => 'array'],
                'max_items' => ['type' => 'integer'],
                'mode' => ['type' => 'select', 'options' => ['default', 'select', 'typeahead']],
                'create' => ['type' => 'toggle'],
            ],
            'select' => [
                'options' => ['type' => 'array'],
                'multiple' => ['type' => 'toggle'],
                'clearable' => ['type' => 'toggle'],
                'searchable' => ['type' => 'toggle'],
                'placeholder' => ['type' => 'text'],
            ],
            'date' => [
                'mode' => ['type' => 'select', 'options' => ['single', 'range']],
                'time_enabled' => ['type' => 'toggle'],
                'time_required' => ['type' => 'toggle'],
                'earliest_date' => ['type' => 'date'],
                'latest_date' => ['type' => 'date'],
                'format' => ['type' => 'text'],
            ],
        ];

        return collect(array_merge($baseConfig, $typeSpecificConfig[$type] ?? []))->mapWithKeys(fn ($item, $index) => [$index => $item])->all();
    }

    /**
     * Check if field type is indexable.
     */
    private function isIndexable(string $type): bool
    {
        $nonIndexable = ['assets', 'replicator', 'grid', 'bard'];

        return ! in_array($type, $nonIndexable);
    }

    /**
     * Check if field type is filterable.
     */
    private function isFilterable(string $type): bool
    {
        $filterable = ['text', 'textarea', 'select', 'radio', 'toggle', 'date', 'entries', 'taxonomy', 'users'];

        return in_array($type, $filterable);
    }

    /**
     * Check if field type is sortable.
     */
    private function isSortable(string $type): bool
    {
        $sortable = ['text', 'textarea', 'date', 'range', 'integer', 'float'];

        return in_array($type, $sortable);
    }
}
