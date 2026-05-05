<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Support\FieldFormatSpec;
use Statamic\Fields\Field;

function makeField(string $type, array $config = []): Field
{
    return new Field('handle', array_merge(['type' => $type], $config));
}

it('returns scalar shape for plain string fieldtypes', function (): void {
    $spec = (new FieldFormatSpec)->for(makeField('text'));

    expect($spec)->toMatchArray(['wire_format' => 'string', 'shape' => 'string']);
});

it('returns boolean shape for toggle', function (): void {
    $spec = (new FieldFormatSpec)->for(makeField('toggle'));

    expect($spec)->toMatchArray(['wire_format' => 'boolean', 'shape' => 'boolean']);
});

it('returns markdown shape with strict no-prosemirror rule', function (): void {
    $spec = (new FieldFormatSpec)->for(makeField('markdown'));

    expect($spec['wire_format'])->toBe('string');
    expect($spec['shape'])->toBe('markdown');
    expect($spec['rules'])->toContain('Plain markdown string. Supports **bold**, *italic*, [links](url), lists, code, etc.');
    expect(implode(' ', $spec['common_mistakes']))->toContain('ProseMirror');
});

it('describes inline bard without paragraph wrapper', function (): void {
    $spec = (new FieldFormatSpec)->for(makeField('bard', [
        'inline' => true,
        'buttons' => ['bold', 'italic'],
    ]));

    expect($spec['shape'])->toBe('bard_inline');
    expect($spec['allowed_node_types'])->toBe(['text', 'hardBreak']);
    expect($spec['allowed_marks'])->toContain('bold');
    expect($spec['allowed_marks'])->toContain('italic');
    expect(implode(' ', $spec['common_mistakes']))->toContain('paragraph');
});

it('describes full bard with allowed sets and recursive set definitions', function (): void {
    $spec = (new FieldFormatSpec)->for(makeField('bard', [
        'inline' => false,
        'buttons' => ['h2', 'h3', 'bold', 'anchor'],
        'sets' => [
            'main' => [
                'sets' => [
                    'callout' => [
                        'display' => 'Callout',
                        'fields' => [
                            ['handle' => 'title', 'field' => ['type' => 'text']],
                            ['handle' => 'content', 'field' => ['type' => 'markdown']],
                        ],
                    ],
                ],
            ],
        ],
    ]));

    expect($spec['shape'])->toBe('bard_block');
    expect($spec['allowed_set_types'])->toBe(['callout']);
    expect($spec['allowed_heading_levels'])->toBe([2, 3]);
    expect($spec['allowed_marks'])->toContain('bold');
    expect($spec['allowed_marks'])->toContain('link');

    expect($spec['set_definitions'])->toHaveKey('callout');
    expect($spec['set_definitions']['callout']['fields'])->toHaveCount(2);

    // Nested markdown field carries its own format spec
    $contentField = collect($spec['set_definitions']['callout']['fields'])
        ->firstWhere('handle', 'content');
    expect($contentField['_format_spec']['shape'])->toBe('markdown');
});

it('describes replicator with item-shape and set definitions', function (): void {
    $spec = (new FieldFormatSpec)->for(makeField('replicator', [
        'sets' => [
            'main' => [
                'sets' => [
                    'hero' => [
                        'display' => 'Hero',
                        'fields' => [
                            ['handle' => 'headline', 'field' => ['type' => 'text']],
                        ],
                    ],
                    'cta' => [
                        'display' => 'CTA',
                        'fields' => [
                            ['handle' => 'button_text', 'field' => ['type' => 'text']],
                        ],
                    ],
                ],
            ],
        ],
    ]));

    expect($spec['shape'])->toBe('replicator_items');
    expect($spec['allowed_set_types'])->toBe(['hero', 'cta']);
    expect($spec['item_required_keys'])->toBe(['id', 'type', 'enabled']);
    expect($spec['set_definitions'])->toHaveKeys(['hero', 'cta']);
    expect($spec['example'][0])->toMatchArray(['id' => 'a1B2c3D4', 'enabled' => true]);
});

it('truncates recursion at max depth so deeply nested sets do not explode the response', function (): void {
    $spec = (new FieldFormatSpec(maxDepth: 0))->for(makeField('replicator', [
        'sets' => [
            'main' => ['sets' => [
                'block' => ['fields' => [
                    ['handle' => 'nested', 'field' => ['type' => 'text']],
                ]],
            ]],
        ],
    ]));

    expect($spec['allowed_set_types'])->toBe(['block']);
    // depth=0 means we don't recurse into set definitions
    expect($spec)->not->toHaveKey('set_definitions');
});

it('respects max depth even when there are nested replicators inside bard sets', function (): void {
    $spec = (new FieldFormatSpec(maxDepth: 1))->for(makeField('bard', [
        'inline' => false,
        'sets' => [
            'main' => ['sets' => [
                'gallery' => ['fields' => [
                    [
                        'handle' => 'images',
                        'field' => [
                            'type' => 'replicator',
                            'sets' => [
                                'main' => ['sets' => [
                                    'image' => ['fields' => [
                                        ['handle' => 'src', 'field' => ['type' => 'assets']],
                                    ]],
                                ]],
                            ],
                        ],
                    ],
                ]],
            ]],
        ],
    ]));

    // Outer bard recurses into 'gallery'
    expect($spec['set_definitions']['gallery']['fields'])->toHaveCount(1);

    $imagesField = $spec['set_definitions']['gallery']['fields'][0];
    expect($imagesField['handle'])->toBe('images');
    expect($imagesField['_format_spec']['shape'])->toBe('replicator_items');

    // Inner replicator's set_definitions should be truncated (depth limit hit)
    expect($imagesField['_format_spec'])->not->toHaveKey('set_definitions');
});

it('reports allowed values for select fields', function (): void {
    $spec = (new FieldFormatSpec)->for(makeField('select', [
        'options' => ['draft' => 'Draft', 'published' => 'Published'],
    ]));

    expect($spec['shape'])->toBe('enum');
    expect($spec['allowed_values'])->toBe(['draft', 'published']);
});

it('reports array shape for multi-select', function (): void {
    $spec = (new FieldFormatSpec)->for(makeField('select', [
        'multiple' => true,
        'options' => ['a', 'b', 'c'],
    ]));

    expect($spec['shape'])->toBe('enum_array');
    expect($spec['wire_format'])->toBe('array');
    expect($spec['allowed_values'])->toBe(['a', 'b', 'c']);
});

it('describes relationship fields with array of UUIDs', function (): void {
    $spec = (new FieldFormatSpec)->for(makeField('entries'));

    expect($spec['shape'])->toBe('relationship_ids');
    expect($spec['wire_format'])->toBe('array');
    expect($spec['item_format'])->toBe('uuid_string');
});

it('describes table fields with cell scalars', function (): void {
    $spec = (new FieldFormatSpec)->for(makeField('table'));

    expect($spec['shape'])->toBe('table_rows');
    expect(implode(' ', $spec['rules']))->toContain('cells');
    expect(implode(' ', $spec['common_mistakes']))->toContain('value');
});

it('returns null for unknown fieldtypes so the response stays small', function (): void {
    $spec = (new FieldFormatSpec)->for(makeField('some_unknown_addon_fieldtype'));

    expect($spec)->toBeNull();
});
