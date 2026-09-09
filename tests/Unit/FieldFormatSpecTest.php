<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Support\FieldFormatSpec;
use Statamic\Facades\Icon;
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

it('reads options written as a list of key/value maps', function (): void {
    // What the Control Panel writes, and what selectSpec used to drop entirely.
    $spec = (new FieldFormatSpec)->for(makeField('select', ['options' => [
        ['key' => 'solid', 'value' => 'Solid Header'],
        ['key' => 'transparent', 'value' => 'Transparent Header'],
    ]]));

    expect($spec['allowed_values'])->toBe(['solid', 'transparent']);
});

it('reads options written as a key to label map', function (): void {
    $spec = (new FieldFormatSpec)->for(makeField('button_group', ['options' => [
        'grid' => 'Grid',
        'carousel' => 'Carousel',
    ]]));

    expect($spec['allowed_values'])->toBe(['grid', 'carousel']);
});

it('reads options written as a flat list', function (): void {
    $spec = (new FieldFormatSpec)->for(makeField('radio', ['options' => ['left', 'center']]));

    expect($spec['allowed_values'])->toBe(['left', 'center']);
});

it('casts non-string option keys to strings', function (): void {
    $spec = (new FieldFormatSpec)->for(makeField('select', ['options' => [
        ['key' => 1, 'value' => 'One'],
        ['key' => 2, 'value' => 'Two'],
    ]]));

    expect($spec['allowed_values'])->toBe(['1', '2']);
});

it('returns no allowed values when a select has no options', function (): void {
    expect((new FieldFormatSpec)->for(makeField('select'))['allowed_values'])->toBe([]);
});

it('still reports enum_array for a multiple select', function (): void {
    $spec = (new FieldFormatSpec)->for(makeField('select', [
        'multiple' => true,
        'options' => [['key' => 'a', 'value' => 'A']],
    ]));

    expect($spec['wire_format'])->toBe('array');
    expect($spec['shape'])->toBe('enum_array');
    expect($spec['allowed_values'])->toBe(['a']);
});

it('lists the icon names a client cannot otherwise discover', function (): void {
    $dir = sys_get_temp_dir() . '/mcp-icons-' . uniqid();
    mkdir($dir, 0777, true);
    foreach (['users', 'location', 'move'] as $name) {
        file_put_contents("{$dir}/{$name}.svg", '<svg></svg>');
    }
    Icon::register('testset', $dir);

    $formatSpec = new FieldFormatSpec;
    $spec = $formatSpec->for(makeField('icon', ['set' => 'testset']));

    expect($spec['shape'])->toBe('icon_name');
    expect($spec['icon_set'])->toBe('testset');
    expect($spec['option_count'])->toBe(3);
    // Names live once per response, not on every field that uses the set.
    expect($spec)->not->toHaveKey('options');
    expect($formatSpec->collectedIconSets()['testset'])->toEqualCanonicalizing(['users', 'location', 'move']);

    array_map('unlink', glob("{$dir}/*.svg") ?: []);
    rmdir($dir);
});

it('degrades to a plain string spec when the icon set is not registered', function (): void {
    $spec = (new FieldFormatSpec)->for(makeField('icon', ['set' => 'nope-not-registered']));

    expect($spec['shape'])->toBe('icon_name');
    expect($spec['icon_set'])->toBe('nope-not-registered');
    expect($spec)->not->toHaveKey('option_count');
    expect(implode(' ', $spec['rules']))->toContain('not registered');
});

it('does not treat an icon field as an opaque string', function (): void {
    // Regression guard: icon used to fall through to stringSpec(), which told
    // a client nothing about which names are valid.
    $spec = (new FieldFormatSpec)->for(makeField('icon'));

    expect($spec['shape'])->not->toBe('string');
    expect($spec['icon_set'])->toBe('default');
});

it('collects an icon set once however many fields use it', function (): void {
    $dir = sys_get_temp_dir() . '/mcp-icons-' . uniqid();
    mkdir($dir, 0777, true);
    foreach (['users', 'location'] as $name) {
        file_put_contents("{$dir}/{$name}.svg", '<svg></svg>');
    }
    Icon::register('sharedset', $dir);

    $formatSpec = new FieldFormatSpec;
    foreach (range(1, 5) as $i) {
        $formatSpec->for(makeField('icon', ['set' => 'sharedset']));
    }

    expect($formatSpec->collectedIconSets())->toHaveCount(1);
    expect($formatSpec->collectedIconSets()['sharedset'])->toHaveCount(2);

    array_map('unlink', glob("{$dir}/*.svg") ?: []);
    rmdir($dir);
});

it('describes the link fieldtype using the references ResolveRedirect actually understands', function (): void {
    $spec = (new FieldFormatSpec)->for(makeField('link'));

    expect($spec['wire_format'])->toBe('string');
    expect($spec['shape'])->toBe('url_or_reference');

    $rules = implode(' ', $spec['rules']);
    expect($rules)->toContain('entry::<entry-id>');
    expect($rules)->toContain('asset::<container>::<path>');
    expect($rules)->toContain('@child');

    expect($spec['examples'])->toContain('entry::3f2b1a44-0c6e-4c3a-9f1e-8b5d6c7a2e10');
    expect($spec['examples'])->toContain('asset::images::brochures/2026.pdf');
});

it('warns against the statamic:// scheme in a link field', function (): void {
    $spec = (new FieldFormatSpec)->for(makeField('link'));

    // Bard link marks use statamic://; ResolveRedirect (the link fieldtype)
    // does not, and stores an unresolvable value verbatim.
    expect(implode(' ', $spec['rules']))->toContain('statamic://');
    expect(implode(' ', $spec['common_mistakes']))->toContain('statamic://entry/<uuid>');
});
