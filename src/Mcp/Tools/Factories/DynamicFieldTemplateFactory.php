<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Factories;

class DynamicFieldTemplateFactory
{
    /**
     * Get field suggestions for LLM.
     *
     * @return array<string, mixed>
     */
    public function getSuggestions(): array
    {
        return [
            'common_fields' => [
                'title' => ['type' => 'text', 'required' => true],
                'content' => ['type' => 'markdown', 'required' => false],
                'slug' => ['type' => 'slug', 'required' => true],
                'date' => ['type' => 'date', 'required' => false],
                'published' => ['type' => 'toggle', 'required' => false],
            ],
            'field_types' => [
                'text', 'textarea', 'markdown', 'date', 'time', 'toggle',
                'select', 'checkboxes', 'radio', 'asset', 'assets',
                'entries', 'terms', 'users', 'collections', 'taxonomies',
                'replicator', 'bard', 'grid', 'table', 'yaml',
            ],
            'patterns' => [
                'blog' => ['title', 'content', 'author', 'date', 'featured_image'],
                'product' => ['title', 'description', 'price', 'images', 'categories'],
                'page' => ['title', 'content', 'template', 'seo_title', 'seo_description'],
            ],
        ];
    }

    /**
     * Validate and process field definitions from LLM.
     *
     * @param  array<string, mixed>  $fields
     *
     * @return array<string, mixed>
     */
    public function validateAndProcessFields(array $fields): array
    {
        $processed = [];

        foreach ($fields as $handle => $field) {
            if (empty($handle)) {
                continue;
            }

            if (! is_array($field)) {
                continue;
            }

            // Ensure field has required properties
            $processed[$handle] = array_merge([
                'type' => 'text',
                'display' => ucwords(str_replace('_', ' ', $handle)),
                'required' => false,
            ], $field);

            // Validate field type
            $validTypes = [
                'text', 'textarea', 'markdown', 'date', 'time', 'toggle',
                'select', 'checkboxes', 'radio', 'asset', 'assets',
                'entries', 'terms', 'users', 'collections', 'taxonomies',
                'replicator', 'bard', 'grid', 'table', 'yaml',
            ];

            if (! in_array($processed[$handle]['type'], $validTypes)) {
                $processed[$handle]['type'] = 'text';
            }
        }

        return $processed;
    }
}
