<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Exceptions\FieldFormatException;
use Cboxdk\StatamicMcp\Mcp\Support\FieldFormatSpec;
use Cboxdk\StatamicMcp\Mcp\Support\FieldtypeExtensions;
use Statamic\Fields\Field;

/**
 * The `seo` fieldtype from aerni/advanced-seo is the worked example: it stores
 * ['source' => ..., 'value' => ...] and dies on a plain string inside its own
 * process(), with a TypeError that names neither the field nor the shape.
 */
function seoField(string $handle = 'seo_title'): Field
{
    return new Field($handle, ['type' => 'seo']);
}

beforeEach(fn () => FieldtypeExtensions::flush());
afterEach(fn () => FieldtypeExtensions::flush());

it('returns no spec for an unknown fieldtype by default', function (): void {
    expect((new FieldFormatSpec)->for(seoField()))->toBeNull();
});

it('serves a registered spec for a fieldtype the package does not know', function (): void {
    FieldtypeExtensions::spec('seo', fn (Field $field): array => [
        'wire_format' => 'object',
        'shape' => 'seo_value',
        'rules' => ['Object with "source" ("custom" or "default") and "value".'],
        'example' => ['source' => 'custom', 'value' => 'Page title'],
    ]);

    $spec = (new FieldFormatSpec)->for(seoField());

    expect($spec['shape'])->toBe('seo_value');
    expect($spec['example'])->toBe(['source' => 'custom', 'value' => 'Page title']);
});

it('passes the field to the resolver so a spec can vary by config', function (): void {
    FieldtypeExtensions::spec('seo', fn (Field $field): array => ['handle' => $field->handle()]);

    expect((new FieldFormatSpec)->for(seoField('seo_description'))['handle'])
        ->toBe('seo_description');
});

it('leaves values untouched when no sanitizer is registered', function (): void {
    expect(FieldtypeExtensions::applySanitizer(seoField(), 'plain string', 'seo_title'))
        ->toBe('plain string');
});

it('applies a registered sanitizer to coerce a value', function (): void {
    FieldtypeExtensions::sanitizer('seo', fn (mixed $value): mixed => is_string($value)
        ? ['source' => 'custom', 'value' => $value]
        : $value);

    expect(FieldtypeExtensions::applySanitizer(seoField(), 'Page title', 'seo_title'))
        ->toBe(['source' => 'custom', 'value' => 'Page title']);
});

it('lets a sanitizer reject a value with a path-bearing error', function (): void {
    FieldtypeExtensions::sanitizer('seo', function (mixed $value, Field $field, string $path): mixed {
        if (! is_array($value)) {
            throw new FieldFormatException("Field [{$path}] expects an object with source and value.");
        }

        return $value;
    });

    expect(fn () => FieldtypeExtensions::applySanitizer(seoField(), 'oops', 'seo_title'))
        ->toThrow(FieldFormatException::class, 'Field [seo_title] expects an object');
});

it('lets a later registration replace an earlier one', function (): void {
    FieldtypeExtensions::spec('seo', fn (): array => ['shape' => 'first']);
    FieldtypeExtensions::spec('seo', fn (): array => ['shape' => 'second']);

    expect((new FieldFormatSpec)->for(seoField())['shape'])->toBe('second');
});

it('reports which fieldtypes have been extended', function (): void {
    FieldtypeExtensions::spec('seo', fn (): array => []);
    FieldtypeExtensions::sanitizer('seo', fn (mixed $v): mixed => $v);
    FieldtypeExtensions::sanitizer('bard_texstyle', fn (mixed $v): mixed => $v);

    expect(FieldtypeExtensions::registered())->toEqualCanonicalizing(['seo', 'bard_texstyle']);
});
