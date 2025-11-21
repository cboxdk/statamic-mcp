<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Support;

use Statamic\Statamic;

/**
 * Helper class for detecting Statamic version and feature availability.
 */
class StatamicVersion
{
    /**
     * Check if running Statamic v6 or later.
     */
    public static function isV6OrLater(): bool
    {
        return version_compare(self::current(), '6.0.0', '>=');
    }

    /**
     * Check if running Statamic v5.65 or later (supports v6 opt-ins).
     */
    public static function supportsV6OptIns(): bool
    {
        return version_compare(self::current(), '5.65.0', '>=');
    }

    /**
     * Check if v6 asset permissions are enabled.
     */
    public static function hasV6AssetPermissions(): bool
    {
        if (! self::supportsV6OptIns()) {
            return false;
        }

        return config('statamic.assets.v6_permissions', false);
    }

    /**
     * Get the current Statamic version.
     */
    public static function current(): string
    {
        return Statamic::version();
    }

    /**
     * Get version information for tool responses.
     *
     * @return array<string, string>
     */
    public static function info(): array
    {
        return [
            'statamic_version' => self::current(),
            'is_v6' => self::isV6OrLater() ? 'true' : 'false',
            'supports_v6_opt_ins' => self::supportsV6OptIns() ? 'true' : 'false',
            'v6_asset_permissions' => self::hasV6AssetPermissions() ? 'enabled' : 'disabled',
        ];
    }

    /**
     * Get the major version number (5 or 6).
     */
    public static function majorVersion(): int
    {
        return (int) explode('.', self::current())[0];
    }

    /**
     * Check if a specific feature is available.
     */
    public static function hasFeature(string $feature): bool
    {
        return match ($feature) {
            'v6_asset_permissions' => self::hasV6AssetPermissions(),
            'v6_opt_ins' => self::supportsV6OptIns(),
            default => false,
        };
    }
}
