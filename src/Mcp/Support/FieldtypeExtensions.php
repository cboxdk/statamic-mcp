<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Support;

use Statamic\Fields\Field;

/**
 * Registry letting a site or addon teach the MCP about a fieldtype it does not
 * ship support for.
 *
 * Statamic's fieldtype set is open, but this package's is closed: an unknown
 * fieldtype gets no wire-format guidance and no input coercion, so a client
 * guesses at the shape and the guess reaches a process() written for
 * Control-Panel input.
 *
 * `aerni/advanced-seo` is the worked example. Its `seo` fieldtype stores
 * ['source' => ..., 'value' => ...]; a plain string dies on $data['source']
 * with "Cannot access offset of type string on string".
 *
 * Register from a service provider's boot(). Registrations are keyed by
 * fieldtype handle, so a later one replaces an earlier one.
 */
class FieldtypeExtensions
{
    /**
     * Wire-format resolvers, keyed by fieldtype handle.
     *
     * @var array<string, callable(Field): (array<string, mixed>|null)>
     */
    private static array $specs = [];

    /**
     * Value sanitizers, keyed by fieldtype handle.
     *
     * @var array<string, callable(mixed, Field, string): mixed>
     */
    private static array $sanitizers = [];

    /**
     * Describe the wire format of a fieldtype the MCP does not know. The
     * resolver returns the structure the built-in specs use, or null.
     *
     * @param  callable(Field): (array<string, mixed>|null)  $resolver
     */
    public static function spec(string $fieldtype, callable $resolver): void
    {
        self::$specs[$fieldtype] = $resolver;
    }

    /**
     * Coerce or reject an incoming value, before validation and the
     * fieldtype's own process(). Throw a FieldFormatException to reject.
     *
     * @param  callable(mixed, Field, string): mixed  $sanitizer
     */
    public static function sanitizer(string $fieldtype, callable $sanitizer): void
    {
        self::$sanitizers[$fieldtype] = $sanitizer;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function resolveSpec(Field $field): ?array
    {
        $resolver = self::$specs[$field->type()] ?? null;

        return $resolver === null ? null : $resolver($field);
    }

    public static function hasSanitizer(Field $field): bool
    {
        return isset(self::$sanitizers[$field->type()]);
    }

    public static function applySanitizer(Field $field, mixed $value, string $path): mixed
    {
        $sanitizer = self::$sanitizers[$field->type()] ?? null;

        return $sanitizer === null ? $value : $sanitizer($value, $field, $path);
    }

    /**
     * @return array<int, string>
     */
    public static function registered(): array
    {
        return array_values(array_unique(array_merge(
            array_keys(self::$specs),
            array_keys(self::$sanitizers)
        )));
    }

    /** Drop every registration. Intended for tests. */
    public static function flush(): void
    {
        self::$specs = [];
        self::$sanitizers = [];
    }
}
