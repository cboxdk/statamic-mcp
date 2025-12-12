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
        $version = self::current();

        // Check major version number to handle alpha/beta releases properly
        // (e.g., "6.0.0-alpha.18" is considered v6)
        $majorVersion = (int) explode('.', $version)[0];

        return $majorVersion >= 6;
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
        // Statamic::version() can return null in test environments
        $version = Statamic::version();

        if ($version !== null) {
            return $version;
        }

        // Fallback: try to get version from installed packages
        return self::getVersionFromComposer();
    }

    /**
     * Get version from composer installed packages.
     */
    private static function getVersionFromComposer(): string
    {
        // Try to read from composer's installed.json
        $installedPath = base_path('vendor/composer/installed.json');

        if (file_exists($installedPath)) {
            $installed = json_decode((string) file_get_contents($installedPath), true);
            $packages = $installed['packages'] ?? $installed;

            foreach ($packages as $package) {
                if (($package['name'] ?? '') === 'statamic/cms') {
                    $version = $package['version'] ?? '5.0.0';

                    // Remove 'v' prefix if present
                    return ltrim($version, 'v');
                }
            }
        }

        // Ultimate fallback - assume v6 if nothing found (safer for forward compatibility)
        return '6.0.0';
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
