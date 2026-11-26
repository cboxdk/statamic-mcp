<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Support;

/**
 * Numbers from config/statamic/mcp.php, whatever shape they arrive in.
 *
 * A config published before v4 read numbers straight from env(), which hands
 * back strings: "60" reached an int return type and the MCP Admin page
 * answered 500. The shipped config now casts, but a published copy does not
 * change on upgrade, so the readers must not trust it either.
 */
final class McpConfig
{
    /** The setting as an integer, the default when it is missing or not numeric. */
    public static function int(string $key, int $default): int
    {
        $value = config("statamic.mcp.{$key}");

        return is_numeric($value) ? (int) $value : $default;
    }

    /** Like int(), but null (unset, or "null" in .env) stays null: no limit. */
    public static function optionalInt(string $key, ?int $default): ?int
    {
        $value = config("statamic.mcp.{$key}", $default);

        return match (true) {
            $value === null => null,
            is_numeric($value) => (int) $value,
            default => $default,
        };
    }
}
