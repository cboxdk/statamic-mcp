<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Support;

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;

/** JSON Schema for the data returned by each built-in action. */
final class ResultSchema
{
    /**
     * Factor exact repetitions in our generated schemas into local definitions.
     * Not for integration schemas: moving their $id/$ref scopes can change meaning.
     * Definitions stay inline and independent of every other tool in tools/list.
     *
     * @param  array<string, mixed>  $schema
     *
     * @return array<string, mixed>
     */
    public static function deduplicate(array $schema): array
    {
        $counts = [];
        self::mapChildren($schema, function (array $node) use (&$counts): array {
            $encoded = json_encode($node, JSON_THROW_ON_ERROR);
            $counts[$encoded] = ($counts[$encoded] ?? 0) + 1;

            return $node;
        });
        // Larger nodes first: a shared record can subsume its repeated fields.
        uksort($counts, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $definitions = [];
        foreach ($counts as $encoded => $count) {
            $name = 's' . count($definitions);
            $reference = ['$ref' => '#/$defs/' . $name];
            if ($count < 2 || strlen($encoded) <= strlen(json_encode($reference, JSON_THROW_ON_ERROR))) {
                continue;
            }
            $definition = null;
            $candidate = self::mapChildren($schema, function (array $node) use ($encoded, $reference, &$definition): array {
                if (json_encode($node, JSON_THROW_ON_ERROR) !== $encoded) {
                    return $node;
                }
                $definition = $node;

                return $reference;
            });
            if ($definition === null) {
                continue;
            }
            $candidate['$defs'] = $definitions + [$name => $definition];
            if (strlen(json_encode($candidate, JSON_THROW_ON_ERROR)) < strlen(json_encode($schema, JSON_THROW_ON_ERROR))) {
                $schema = $candidate;
                $definitions[$name] = $definition;
            }
        }

        return $schema;
    }

    /**
     * Visit only schema positions used by ResultSchema, never property names,
     * enum/const values, annotations or definitions already extracted above.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $schema
     * @param  callable(array<array-key, mixed>): array<array-key, mixed>  $visit
     *
     * @return array<TKey, mixed>
     */
    private static function mapChildren(array $schema, callable $visit): array
    {
        foreach ($schema as $keyword => $value) {
            if (! is_array($value)) {
                continue;
            }
            if (in_array($keyword, ['properties', 'anyOf'], true)) {
                foreach ($value as $key => $child) {
                    if (is_array($child)) {
                        $mapped = $visit($child);
                        $value[$key] = $mapped === $child ? self::mapChildren($child, $visit) : $mapped;
                    }
                }
                $schema[$keyword] = $value;
            } elseif (in_array($keyword, ['items', 'if', 'then', 'else', 'not'], true)) {
                $mapped = $visit($value);
                $schema[$keyword] = $mapped === $value ? self::mapChildren($value, $visit) : $mapped;
            }
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    public static function action(string $domain, string $action): array
    {
        $string = ['type' => 'string'];
        $boolean = ['type' => 'boolean'];
        $map = self::map();

        if (in_array($action, ['list', 'scan', 'search', 'types', 'list_revisions', 'list_submissions', 'export', 'list_fieldsets', 'fieldtypes'], true)) {
            return self::object([
                'items' => ['type' => 'array', 'items' => self::record()],
                'next_cursor' => ['type' => ['string', 'null']],
                'total' => ['type' => 'integer', 'minimum' => 0],
                'truncated' => $boolean,
            ], ['items', 'next_cursor']);
        }

        return match ($domain) {
            'entries' => $action === 'get_revision'
                ? self::object(['entry_id' => $string, 'revision' => self::record()], ['entry_id', 'revision'])
                : self::wrapped('entry'),
            'terms' => self::wrapped('term'),
            'globals' => self::wrapped('global'),
            'forms' => match ($action) {
                'summary' => self::object(['summary' => self::map()], ['summary']),
                'get_submission', 'delete_submission', 'mark_spam' => self::object(['submission' => self::record()], ['submission']),
                default => self::wrapped('form'),
            },
            'blueprints' => $action === 'validate'
                ? self::object(['blueprint' => $string, 'validation' => self::object([
                    'valid' => $boolean, 'errors' => ['type' => 'array'], 'warnings' => ['type' => 'array'],
                ], ['valid', 'errors', 'warnings'])], ['blueprint', 'validation'])
                : self::wrapped(str_ends_with($action, '_fieldset') ? 'fieldset' : 'blueprint'),
            'assets' => $action === 'copy'
                ? self::object(['original' => self::record(), 'copy' => self::record('asset', true)], ['original', 'copy'])
                : ['anyOf' => [self::wrapped('asset'), self::wrapped('container')]],
            'users' => ['anyOf' => [self::wrapped('user', $action !== 'get'), self::wrapped('role'), self::wrapped('group')]],
            'structures' => ['anyOf' => array_map(self::wrapped(...), ['collection', 'taxonomy', 'navigation', 'globalset', 'site'])],
            'system' => match ($action) {
                'reindex' => self::object(['indexes' => ['type' => 'array', 'items' => $string], 'status' => ['const' => 'submitted']], ['indexes', 'status']),
                'invalidate_urls' => self::object(['urls' => ['type' => 'array', 'items' => $string], 'status' => ['const' => 'invalidated']], ['urls', 'status']),
                'info' => self::object(['system_info' => self::object([
                    'environment' => $string, 'debug_mode' => $boolean, 'timezone' => $string,
                ], ['environment', 'debug_mode', 'timezone'])], ['system_info']),
                'health' => self::object(['health' => self::object([
                    'overall_status' => ['enum' => ['healthy', 'degraded', 'unhealthy']],
                    'checks' => $map, 'timestamp' => $string,
                ], ['overall_status', 'checks', 'timestamp'])], ['health']),
                'cache_status' => self::object(['cache_status' => self::object([
                    'timestamp' => $string, 'caches' => $map,
                ], ['timestamp', 'caches'])], ['cache_status']),
                'cache_clear', 'cache_warm' => self::object([
                    $action === 'cache_clear' ? 'cache_cleared' : 'cache_warmed' => $map,
                    'timestamp' => $string,
                ], [$action === 'cache_clear' ? 'cache_cleared' : 'cache_warmed', 'timestamp']),
                'config_get' => self::object(['config' => self::object([
                    'key' => $string, 'value' => new \stdClass,
                ], ['key', 'value'])], ['config']),
                default => throw new \LogicException("No output schema for {$domain}.{$action}"),
            },
            'content-facade' => match ($action) {
                'content_audit' => self::object([
                    'workflow' => ['const' => $action], 'summary' => $map, 'details' => $map,
                    'recommendations' => ['type' => 'array'],
                ], ['workflow', 'summary', 'details', 'recommendations']),
                'cross_reference' => self::object([
                    'workflow' => ['const' => $action], 'relationships' => $map, 'statistics' => $map,
                ], ['workflow', 'relationships', 'statistics']),
                'content_validate' => self::object([
                    'workflow' => ['const' => $action], 'summary' => $map,
                    'findings' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'findings_truncated' => $boolean, 'next_cursor' => ['type' => ['string', 'null']],
                    'total' => ['type' => 'integer', 'minimum' => 0],
                ], ['workflow', 'summary', 'findings', 'findings_truncated', 'next_cursor', 'total']),
                default => throw new \LogicException("No output schema for {$domain}.{$action}"),
            },
            default => throw new \LogicException("No output schema for {$domain}.{$action}"),
        };
    }

    /** @return array<string, mixed> */
    public static function wrapped(string $key, bool $full = true): array
    {
        return self::object([$key => self::record($key, $full)], [$key]);
    }

    /**
     * Optional fields can be absent after resource-policy filtering. Content
     * fields are site-defined; identity and version fields retain fixed types.
     *
     * @return array<string, mixed>
     */
    private static function record(string $kind = '', bool $full = false): array
    {
        $string = ['type' => 'string'];
        $nullable = ['type' => ['string', 'null']];
        $boolean = ['type' => 'boolean'];
        $strings = ['type' => 'array', 'items' => $string];
        $properties = [
            'id' => ['type' => 'string'],
            'handle' => ['type' => 'string'],
            'slug' => ['type' => 'string'],
            'version' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            'data' => self::map(),
        ];
        $properties += match ($kind) {
            'entry' => [
                'collection' => $string, 'site' => $string, 'published' => $boolean, 'status' => $string,
                'url' => $nullable, 'parent' => $nullable, 'date' => $nullable, 'blueprint' => $nullable,
                'origin' => ['anyOf' => [self::object(['id' => $string, 'site' => $string], ['id', 'site']), ['type' => 'null']]],
                'localizations' => ['type' => 'array', 'items' => self::object(['id' => $string, 'site' => $string], ['id', 'site'])],
                'merged_data' => self::map(),
            ],
            'term' => ['taxonomy' => $string, 'site' => $string, 'url' => $nullable],
            'global' => ['site' => $string, 'localized' => $boolean, 'sites' => $strings],
            'user' => ['email' => $string, 'name' => $nullable, 'super' => $boolean, 'roles' => $strings, 'groups' => $strings],
            'role' => ['title' => $string, 'super' => $boolean, 'permissions' => $strings],
            'group' => ['title' => $string, 'roles' => $strings],
            'blueprint' => ['namespace' => $nullable, 'hidden' => $boolean, 'fields' => ['type' => ['array', 'object']]],
            'asset' => ['path' => $string, 'container' => $string, 'size' => ['type' => 'integer', 'minimum' => 0], 'url' => $nullable, 'is_image' => $boolean],
            'container' => ['disk' => $string, 'url' => $nullable, 'path' => $nullable],
            'collection' => ['title' => $string, 'dated' => $boolean, 'orderable' => $boolean, 'blueprints' => $strings, 'tree' => ['type' => 'array']],
            'taxonomy' => ['title' => $string, 'sites' => $strings],
            'navigation' => ['title' => $string, 'trees' => ['type' => 'array']],
            'globalset' => ['title' => $string, 'sites' => $strings],
            default => [],
        };
        $required = $full ? match ($kind) {
            'entry' => ['id', 'collection', 'site', 'published', 'data', 'version'],
            'term' => ['id', 'taxonomy', 'site', 'data', 'version'],
            'global' => ['handle', 'site', 'data', 'version'],
            'user' => ['id', 'email', 'super', 'data', 'version'],
            'asset' => ['id', 'path', 'container', 'data', 'version'],
            'site' => ['handle'],
            default => ['handle', 'version'],
        } : [];

        // These records apply denied_fields to the whole record, not just data.
        $policyDomain = match ($kind) {
            'collection', 'taxonomy', 'navigation', 'globalset', 'site' => 'structures',
            'asset', 'container' => 'assets',
            'role', 'group' => 'users',
            'blueprint', 'fieldset' => 'blueprints',
            default => null,
        };
        if ($policyDomain !== null) {
            $required = array_values(array_diff($required, app(ResourcePolicy::class)->getDeniedFields($policyDomain)));
        }

        $record = self::object($properties, $required);

        return $policyDomain !== null && $required === []
            ? ['anyOf' => [$record, ['type' => 'array', 'maxItems' => 0]]]
            : $record;
    }

    /** PHP serializes an empty associative array as [], not {}.
     * @return array<string, mixed>
     */
    public static function map(): array
    {
        return ['anyOf' => [['type' => 'object'], ['type' => 'array', 'maxItems' => 0]]];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  list<string>  $required
     *
     * @return array<string, mixed>
     */
    public static function object(array $properties, array $required = []): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => $required];
    }
}
