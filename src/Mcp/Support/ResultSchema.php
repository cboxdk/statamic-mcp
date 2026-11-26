<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Support;

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;

/** JSON Schema for the data returned by each built-in action. */
final class ResultSchema
{
    /** @return array<string, mixed> */
    public static function action(string $domain, string $action): array
    {
        $string = ['type' => 'string'];
        $boolean = ['type' => 'boolean'];
        $map = self::map();

        if (in_array($action, ['list', 'scan', 'search', 'types', 'list_revisions', 'list_submissions', 'export'], true)) {
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
                : self::wrapped('blueprint'),
            'assets' => $action === 'copy'
                ? self::object(['original' => self::record(), 'copy' => self::record('asset', true)], ['original', 'copy'])
                : ['anyOf' => [self::wrapped('asset'), self::wrapped('container')]],
            'users' => ['anyOf' => [self::wrapped('user', $action !== 'get'), self::wrapped('role'), self::wrapped('group')]],
            'structures' => ['anyOf' => array_map(self::wrapped(...), ['collection', 'taxonomy', 'navigation', 'globalset', 'site'])],
            'system' => match ($action) {
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
            'entry' => ['collection' => $string, 'site' => $string, 'published' => $boolean, 'status' => $string, 'url' => $nullable, 'parent' => $nullable, 'date' => $nullable],
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
            'blueprint' => 'blueprints',
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
