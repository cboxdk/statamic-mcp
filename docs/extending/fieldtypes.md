---
title: "Fieldtypes"
description: "Register a wire-format spec and an input sanitizer for a fieldtype the package does not ship support for"
weight: 10
---

# Extending fieldtypes

The package knows the wire format of Statamic's built-in fieldtypes. It cannot
know the ones an addon adds, and an unknown fieldtype gets two things wrong:

- **No format guidance.** `statamic-blueprints get` reports no `_format_spec`
  for the field, so a client has to guess at the shape from the handle alone.
- **No input coercion.** The guessed value reaches the fieldtype's own
  `process()`, which was written for Control Panel input and may not survive
  contact with it.

`aerni/advanced-seo` is the worked example. Its `seo` fieldtype stores
`['source' => ..., 'value' => ...]`; a plain string dies inside its `process()`
with `Cannot access offset of type string on string` — an error that names
neither the field nor the expected shape.

`FieldtypeExtensions` lets you supply both halves.

## Registering

Register from a service provider's `boot()`:

```php
use Cboxdk\StatamicMcp\Mcp\Support\FieldtypeExtensions;
use Statamic\Fields\Field;

public function boot(): void
{
    FieldtypeExtensions::spec('seo', fn (Field $field): array => [
        'wire_format' => 'object',
        'shape' => 'seo_value',
        'rules' => ['Object with "source" ("custom" or "default") and "value".'],
        'example' => ['source' => 'custom', 'value' => 'Page title'],
    ]);

    FieldtypeExtensions::sanitizer('seo', fn (mixed $value): mixed => is_string($value)
        ? ['source' => 'custom', 'value' => $value]
        : $value);
}
```

Registrations are keyed by fieldtype handle, so a later one replaces an earlier
one. Registering only a spec, or only a sanitizer, is fine — they are
independent.

## Specs

A spec resolver receives the `Field` and returns the same structure the built-in
specs use, or `null` to say nothing. Because it receives the field, a spec can
vary with configuration:

```php
FieldtypeExtensions::spec('seo', fn (Field $field): array => [
    'wire_format' => 'object',
    'shape' => $field->get('multiple') ? 'seo_value_list' : 'seo_value',
]);
```

The keys are advisory text for the client, not a schema the server enforces.
`wire_format` and `shape` are the two every built-in spec carries; `rules`,
`examples` and `common_mistakes` are all read by clients when present.

## Sanitizers

A sanitizer runs on the incoming value before validation and before the
fieldtype's own `process()`. It receives the value, the `Field`, and the dot
path to the field within the payload. Return the coerced value, or throw
`FieldFormatException` to reject the write:

```php
use Cboxdk\StatamicMcp\Mcp\Exceptions\FieldFormatException;

FieldtypeExtensions::sanitizer('seo', function (mixed $value, Field $field, string $path): mixed {
    if (is_string($value)) {
        return ['source' => 'custom', 'value' => $value];
    }

    if (! is_array($value) || ! array_key_exists('source', $value)) {
        throw new FieldFormatException(
            "Field [{$path}] expects an object with \"source\" and \"value\"."
        );
    }

    return $value;
});
```

The path matters: it is what turns "something in this entry was the wrong shape"
into a message a client can act on. Include it in every error you throw.

Sanitizers run wherever field data is sanitized, so they apply inside replicator
sets, grid rows, bard sets and groups as well as at the top level.

## Introspection and tests

`FieldtypeExtensions::registered()` lists the handles that have a spec or a
sanitizer. `FieldtypeExtensions::flush()` drops every registration — the
registry is static, so a test that registers should flush in `setUp()` and
`tearDown()` to avoid leaking into other tests.
