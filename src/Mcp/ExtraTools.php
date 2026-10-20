<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use InvalidArgumentException;

/**
 * Tools other packages add to the Statamic MCP server.
 *
 * A package registers its own tools from its service provider, or an operator
 * lists them in `statamic.mcp.extra_tools`. They are served exactly like the
 * built-in routers: listed in the catalog or reached through search_tools, and
 * gated by the same web toggle, token scope, Statamic permissions, resource
 * policy, confirmation gate and audit log — because they must be routers.
 *
 * A router's token scope is derived from its domain (`{domain}:read|write`).
 * A domain this package has no scope for would otherwise require none, so an
 * extra tool whose domain has no scope requires a full-access token instead:
 * a third-party tool can never be reachable by a token that was not meant for it.
 */
final class ExtraTools
{
    /**
     * Tools registered at runtime, keyed by class, valued by whether the tool
     * is reached through search_tools (true) or listed in the catalog (false).
     *
     * @var array<class-string<BaseRouter>, bool>
     */
    private static array $registered = [];

    /**
     * Register a tool.
     *
     * Call it from a service provider's boot() method. Searchable by default,
     * so the tool costs a client nothing until it is asked for.
     *
     * @param  string  $class  a class extending BaseRouter; anything else is refused
     */
    public static function register(string $class, bool $searchable = true): void
    {
        self::assertRouter($class);

        self::$registered[$class] = $searchable;
    }

    /**
     * Extra tools listed in the catalog at connect time.
     *
     * @return list<class-string<BaseRouter>>
     */
    public static function core(): array
    {
        return array_keys(array_filter(self::all(), fn (bool $searchable): bool => ! $searchable));
    }

    /**
     * Extra tools reached through search_tools and execute_tools.
     *
     * @return list<class-string<BaseRouter>>
     */
    public static function searchable(): array
    {
        return array_keys(array_filter(self::all(), fn (bool $searchable): bool => $searchable));
    }

    /**
     * Every extra tool, keyed by class, valued by whether it is searchable.
     *
     * Config entries come first; a runtime registration of the same class wins.
     *
     * @return array<class-string<BaseRouter>, bool>
     */
    public static function all(): array
    {
        return [...self::fromConfig(), ...self::$registered];
    }

    /**
     * Whether the class was added by another package rather than shipped here.
     */
    public static function isRegistered(string $class): bool
    {
        return array_key_exists($class, self::all());
    }

    /**
     * Forget runtime registrations. For tests.
     */
    public static function flush(): void
    {
        self::$registered = [];
    }

    /**
     * Entries from `statamic.mcp.extra_tools`: a class name (searchable), or
     * `['class' => …, 'searchable' => bool]`.
     *
     * @return array<class-string<BaseRouter>, bool>
     */
    private static function fromConfig(): array
    {
        $entries = config('statamic.mcp.extra_tools', []);

        if (! is_array($entries)) {
            throw new InvalidArgumentException('statamic.mcp.extra_tools must be an array of tool classes.');
        }

        $tools = [];

        foreach ($entries as $entry) {
            [$class, $searchable] = is_array($entry)
                ? [$entry['class'] ?? null, (bool) ($entry['searchable'] ?? true)]
                : [$entry, true];

            if (! is_string($class)) {
                throw new InvalidArgumentException('Each statamic.mcp.extra_tools entry needs a tool class name.');
            }

            self::assertRouter($class);

            $tools[$class] = $searchable;
        }

        return $tools;
    }

    /**
     * @phpstan-assert class-string<BaseRouter> $class
     */
    private static function assertRouter(string $class): void
    {
        if (! class_exists($class) || ! is_subclass_of($class, BaseRouter::class)) {
            throw new InvalidArgumentException(
                "MCP extra tool [{$class}] must be a class extending " . BaseRouter::class
                . ', so the token scope, permission, resource policy and audit checks apply to it.'
            );
        }
    }
}
