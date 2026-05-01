<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Support;

use Illuminate\Support\Collection;
use Statamic\Fields\Field;
use Statamic\Fieldtypes\Bard;
use Statamic\Fieldtypes\Grid;
use Statamic\Fieldtypes\Group;
use Statamic\Fieldtypes\Replicator;

/**
 * Derives an agent-friendly wire-format specification from a Statamic Field.
 *
 * Blueprints expose raw fieldtype config (sets, buttons, options, etc.) but not
 * the *wire format* — the exact shape an MCP client must produce when writing
 * an entry. This service projects field config into a structured spec that
 * names the shape, lists allowed types, recurses into nested set fields, and
 * includes a small canonical example. The goal is to remove guess-work for
 * agents working with bard, replicator, grid, group, and similar structured
 * fieldtypes.
 */
class FieldFormatSpec
{
    public function __construct(
        private int $maxDepth = 2,
    ) {}

    /**
     * Build a wire-format specification for a single field.
     *
     * Returns null for fieldtypes where no specific guidance is available.
     *
     * @return array<string, mixed>|null
     */
    public function for(Field $field, int $depth = 0): ?array
    {
        if ($depth > $this->maxDepth) {
            return [
                'wire_format' => 'truncated',
                'shape' => 'truncated',
                'rules' => ["Recursion truncated at depth {$this->maxDepth}. Re-fetch this field's blueprint with a higher max_format_depth to see nested definitions."],
            ];
        }

        return match ($field->type()) {
            'bard' => $this->bardSpec($field, $depth),
            'replicator' => $this->replicatorSpec($field, $depth),
            'grid' => $this->gridSpec($field, $depth),
            'group' => $this->groupSpec($field, $depth),
            'markdown' => $this->markdownSpec(),
            'text', 'textarea', 'slug', 'code', 'yaml', 'html', 'video', 'color', 'icon' => $this->stringSpec(),
            'integer' => ['wire_format' => 'integer', 'shape' => 'integer'],
            'float' => ['wire_format' => 'number', 'shape' => 'number'],
            'toggle' => ['wire_format' => 'boolean', 'shape' => 'boolean'],
            'select', 'radio', 'button_group' => $this->selectSpec($field),
            'checkboxes' => $this->checkboxesSpec($field),
            'entries', 'terms', 'users', 'taxonomy' => $this->relationshipSpec($field),
            'assets' => $this->assetsSpec($field),
            'date' => [
                'wire_format' => 'string_or_object',
                'shape' => 'date',
                'rules' => [
                    'Accepts any of: ISO 8601 datetime ("2024-01-15T12:00:00.000Z"), date-only ("2024-01-15"), date-time ("2024-01-15 12:00"), or split object ({"date":"2024-01-15","time":"12:00"}).',
                    'The MCP server normalizes all of these to the Statamic-required ISO 8601 Zulu format with milliseconds before validation.',
                ],
                'examples' => [
                    '2024-01-15T12:00:00.000Z',
                    '2024-01-15',
                    '2024-01-15 12:00',
                    ['date' => '2024-01-15', 'time' => '12:00'],
                ],
            ],
            'time' => [
                'wire_format' => 'string',
                'shape' => 'time',
                'rules' => ['Time string in HH:MM or HH:MM:SS (24-hour).'],
            ],
            'table' => $this->tableSpec(),
            'link' => [
                'wire_format' => 'string',
                'shape' => 'url_or_entry_reference',
                'rules' => ['URL string OR statamic://entry/<uuid> reference.'],
            ],
            default => null,
        };
    }

    /**
     * Build specs for a collection of fields.
     *
     * @param  Collection<string, Field>  $fields
     *
     * @return list<array<string, mixed>>
     */
    public function fieldsSpec(Collection $fields, int $depth = 0): array
    {
        $out = [];
        foreach ($fields as $handle => $field) {
            $entry = [
                'handle' => (string) $handle,
                'type' => $field->type(),
            ];

            $spec = $this->for($field, $depth);
            if ($spec !== null) {
                $entry['_format_spec'] = $spec;
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function bardSpec(Field $field, int $depth): array
    {
        $fieldtype = $field->fieldtype();

        $config = $field->config();
        $isInline = (bool) ($config['inline'] ?? false);
        /** @var array<int, mixed> $rawButtons */
        $rawButtons = is_array($config['buttons'] ?? null) ? $config['buttons'] : [];
        $buttons = array_values(array_filter($rawButtons, 'is_string'));

        $marks = $this->bardMarksFromButtons($buttons);
        $allowedHeadings = $this->bardHeadingsFromButtons($buttons);

        if ($isInline) {
            return [
                'wire_format' => 'array',
                'shape' => 'bard_inline',
                'rules' => [
                    'Inline Bard: array of inline ProseMirror nodes only.',
                    'Do NOT wrap content in {type:"paragraph",...} nodes — paragraph is forbidden in inline mode.',
                    'Allowed nodes: text, hardBreak.',
                ],
                'allowed_node_types' => ['text', 'hardBreak'],
                'allowed_marks' => $marks,
                'common_mistakes' => [
                    'Wrapping nodes in a paragraph — inline bard rejects block-level wrappers.',
                    'Sending a plain string instead of an array of inline nodes.',
                ],
                'example' => [
                    ['type' => 'text', 'text' => 'Hello '],
                    ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'world'],
                ],
            ];
        }

        $allowedSetTypes = [];
        $setDefinitions = [];

        if ($fieldtype instanceof Bard) {
            /** @var Collection<string, array<string, mixed>> $sets */
            $sets = $fieldtype->flattenedSetsConfig();
            $allowedSetTypes = array_keys($sets->all());

            if ($depth < $this->maxDepth) {
                foreach ($allowedSetTypes as $setHandle) {
                    /** @var Collection<string, Field> $setFields */
                    $setFields = $fieldtype->fields($setHandle)->all();
                    $setDefinitions[$setHandle] = [
                        'fields' => $this->fieldsSpec($setFields, $depth + 1),
                    ];
                }
            }
        }

        $spec = [
            'wire_format' => 'array',
            'shape' => 'bard_block',
            'rules' => [
                'Full Bard: array of ProseMirror block nodes.',
                'Custom blocks (sets) appear as { type: "set", attrs: { values: { type: <set_handle>, ...fields } } }.',
                'Set handle goes inside attrs.values.type, NOT on the outer node.',
                'Inside set fields, the same wire-format rules apply per fieldtype (markdown is a string, nested bard is nodes, etc.).',
            ],
            'allowed_node_types' => ['paragraph', 'heading', 'bulletList', 'orderedList', 'listItem', 'set', 'hardBreak'],
            'allowed_marks' => $marks,
            'allowed_heading_levels' => $allowedHeadings,
            'allowed_set_types' => $allowedSetTypes,
            'set_node_shape' => [
                'type' => 'set',
                'attrs' => [
                    'values' => [
                        'type' => '<one of allowed_set_types>',
                        '...' => 'fields per set_definitions[type]',
                    ],
                ],
            ],
            'common_mistakes' => [
                'Sending a markdown string instead of a ProseMirror tree.',
                'Putting the set handle on the outer node — it must go in attrs.values.type.',
                'Sending ProseMirror to a markdown sub-field (e.g. callout.content). Markdown sub-fields take plain markdown strings.',
                'Missing the attrs.values wrapper around set field data.',
            ],
            'example' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body paragraph']]],
                [
                    'type' => 'set',
                    'attrs' => [
                        'values' => [
                            'type' => '<set_handle>',
                        ],
                    ],
                ],
            ],
        ];

        if ($setDefinitions !== []) {
            $spec['set_definitions'] = $setDefinitions;
        }

        return $spec;
    }

    /**
     * @return array<string, mixed>
     */
    private function replicatorSpec(Field $field, int $depth): array
    {
        $fieldtype = $field->fieldtype();

        $allowedSetTypes = [];
        $setDefinitions = [];

        if ($fieldtype instanceof Replicator) {
            /** @var Collection<string, array<string, mixed>> $sets */
            $sets = $fieldtype->flattenedSetsConfig();
            $allowedSetTypes = array_keys($sets->all());

            if ($depth < $this->maxDepth) {
                foreach ($allowedSetTypes as $setHandle) {
                    /** @var Collection<string, Field> $setFields */
                    $setFields = $fieldtype->fields($setHandle)->all();
                    $setDefinitions[$setHandle] = [
                        'fields' => $this->fieldsSpec($setFields, $depth + 1),
                    ];
                }
            }
        }

        $spec = [
            'wire_format' => 'array',
            'shape' => 'replicator_items',
            'rules' => [
                'Replicator: array of items.',
                'Each item: { id, type, enabled, ...fields per set_definitions[type] }.',
                'id: short alphanumeric string, unique within the array (e.g. "a1B2c3D4").',
                'type: must be one of allowed_set_types.',
                'enabled: boolean — true unless deliberately disabling the item.',
            ],
            'item_required_keys' => ['id', 'type', 'enabled'],
            'allowed_set_types' => $allowedSetTypes,
            'item_shape' => [
                'id' => '<unique_id>',
                'type' => '<one of allowed_set_types>',
                'enabled' => true,
                '...' => 'fields per set_definitions[type]',
            ],
            'common_mistakes' => [
                'Forgetting id, type, or enabled on items.',
                'Using a set handle not in allowed_set_types.',
                'Reusing the same id across items in the array.',
            ],
            'example' => [
                ['id' => 'a1B2c3D4', 'type' => '<set_handle>', 'enabled' => true],
            ],
        ];

        if ($setDefinitions !== []) {
            $spec['set_definitions'] = $setDefinitions;
        }

        return $spec;
    }

    /**
     * @return array<string, mixed>
     */
    private function gridSpec(Field $field, int $depth): array
    {
        $fieldtype = $field->fieldtype();
        $rowFields = [];

        if ($fieldtype instanceof Grid && $depth < $this->maxDepth) {
            /** @var Collection<string, Field> $fields */
            $fields = $fieldtype->fields()->all();
            $rowFields = $this->fieldsSpec($fields, $depth + 1);
        }

        return [
            'wire_format' => 'array',
            'shape' => 'grid_rows',
            'rules' => [
                'Grid: array of row objects.',
                'Each row is an object containing the row fields directly (NO id/type/enabled wrapper).',
            ],
            'row_fields' => $rowFields,
            'common_mistakes' => [
                'Adding a "type" key on rows — that is for replicator, not grid.',
                'Wrapping rows in { values: ... } — grid rows are flat objects.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function groupSpec(Field $field, int $depth): array
    {
        $fieldtype = $field->fieldtype();
        $groupFields = [];

        if ($fieldtype instanceof Group && $depth < $this->maxDepth) {
            /** @var Collection<string, Field> $fields */
            $fields = $fieldtype->fields()->all();
            $groupFields = $this->fieldsSpec($fields, $depth + 1);
        }

        return [
            'wire_format' => 'object',
            'shape' => 'group',
            'rules' => [
                'Group: a single object containing the group fields directly.',
            ],
            'group_fields' => $groupFields,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function markdownSpec(): array
    {
        return [
            'wire_format' => 'string',
            'shape' => 'markdown',
            'rules' => [
                'Plain markdown string. Supports **bold**, *italic*, [links](url), lists, code, etc.',
                'Do NOT send a ProseMirror node tree — markdown fields take a string only.',
            ],
            'common_mistakes' => [
                'Sending an array of ProseMirror nodes (that is for bard fields, not markdown).',
            ],
            'example' => '**Heads up** — see [pricing](https://example.com).',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stringSpec(): array
    {
        return ['wire_format' => 'string', 'shape' => 'string'];
    }

    /**
     * @return array<string, mixed>
     */
    private function selectSpec(Field $field): array
    {
        $config = $field->config();
        $multiple = (bool) ($config['multiple'] ?? false);
        $rawOptions = $config['options'] ?? [];
        $options = [];
        if (is_array($rawOptions)) {
            // Statamic options can be either a flat list or a key=>label map.
            $isAssoc = array_keys($rawOptions) !== range(0, count($rawOptions) - 1);
            $options = $isAssoc ? array_keys($rawOptions) : array_values(array_filter($rawOptions, 'is_scalar'));
        }

        return [
            'wire_format' => $multiple ? 'array' : 'string',
            'shape' => $multiple ? 'enum_array' : 'enum',
            'allowed_values' => array_map(static fn ($v): string => (string) $v, $options),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkboxesSpec(Field $field): array
    {
        $config = $field->config();
        $rawOptions = $config['options'] ?? [];
        $options = [];
        if (is_array($rawOptions)) {
            $isAssoc = array_keys($rawOptions) !== range(0, count($rawOptions) - 1);
            $options = $isAssoc ? array_keys($rawOptions) : array_values(array_filter($rawOptions, 'is_scalar'));
        }

        return [
            'wire_format' => 'array',
            'shape' => 'enum_array',
            'allowed_values' => array_map(static fn ($v): string => (string) $v, $options),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function relationshipSpec(Field $field): array
    {
        $config = $field->config();
        $maxItems = $config['max_items'] ?? null;
        $singular = $maxItems === 1;

        return [
            'wire_format' => 'array',
            'shape' => 'relationship_ids',
            'rules' => [
                $singular
                    ? 'Single-relationship field. Wire format is still an array — wrap the single UUID in [].'
                    : 'Array of UUIDs referencing the related resources.',
            ],
            'item_format' => 'uuid_string',
            'common_mistakes' => [
                'Sending a bare string — relationship fields always expect arrays.',
                'Including a prefix like "entry::" — pass the UUID directly.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assetsSpec(Field $field): array
    {
        $config = $field->config();
        $maxFiles = $config['max_files'] ?? null;
        $singular = $maxFiles === 1;

        return [
            'wire_format' => $singular ? 'string' : 'array',
            'shape' => $singular ? 'asset_path' : 'asset_paths',
            'rules' => [
                $singular
                    ? 'Single asset path string, e.g. "/assets/foo.png".'
                    : 'Array of asset path strings.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tableSpec(): array
    {
        return [
            'wire_format' => 'array',
            'shape' => 'table_rows',
            'rules' => [
                'Table: array of row objects.',
                'Each row: { cells: [<scalar|null>, <scalar|null>, ...] }.',
                'Cell values must be scalar (string|number|null) — NOT objects.',
            ],
            'common_mistakes' => [
                'Wrapping cells as { value: x } — pass the scalar directly.',
                'Sending an array of arrays without the cells wrapper.',
            ],
            'example' => [
                ['cells' => ['Header A', 'Header B']],
                ['cells' => ['Row 1 A', 'Row 1 B']],
            ],
        ];
    }

    /**
     * Map Bard buttons config to ProseMirror mark types.
     *
     * @param  list<string>  $buttons
     *
     * @return list<string>
     */
    private function bardMarksFromButtons(array $buttons): array
    {
        $map = [
            'bold' => 'bold',
            'italic' => 'italic',
            'underline' => 'underline',
            'strikethrough' => 'strike',
            'code' => 'code',
            'subscript' => 'subscript',
            'superscript' => 'superscript',
            'small' => 'small',
            'anchor' => 'link',
        ];

        $marks = [];
        foreach ($buttons as $btn) {
            if (isset($map[$btn])) {
                $marks[] = $map[$btn];
            }
        }

        return array_values(array_unique($marks));
    }

    /**
     * Map Bard buttons config to allowed heading levels.
     *
     * @param  list<string>  $buttons
     *
     * @return list<int>
     */
    private function bardHeadingsFromButtons(array $buttons): array
    {
        $levels = [];
        foreach ($buttons as $btn) {
            if (preg_match('/^h([1-6])$/', $btn, $m) === 1) {
                $levels[] = (int) $m[1];
            }
        }
        sort($levels);

        return array_values(array_unique($levels));
    }
}
