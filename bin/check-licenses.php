#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Fail the build if any locked dependency is not offered under a permissive license.
 *
 * Reads composer.lock directly — no plugins, no network. SPDX dual-licensing is
 * honoured: a package passes if ANY of its declared licenses is permissive, so
 * "BSD-3-Clause OR GPL-3.0-only" passes on the BSD arm.
 */
const PERMISSIVE = [
    '0BSD',
    'AFL-2.1',
    'Apache-2.0',
    'BSD-2-Clause',
    'BSD-3-Clause',
    'BSD-4-Clause',
    'CC0-1.0',
    'ISC',
    'LGPL-2.1-only',
    'LGPL-2.1-or-later',
    'LGPL-3.0-only',
    'LGPL-3.0-or-later',
    'MIT',
    'MPL-2.0',
    'Unlicense',
    'WTFPL',
    'Zlib',
];

/**
 * Packages allowed through despite a non-permissive license string.
 * Every entry needs a justification.
 *
 * @var array<string, string>
 */
const EXCEPTIONS = [
    // Statamic ships source-available under its own proprietary license. This
    // package is a Statamic addon, so the dependency is inherent rather than a
    // choice — it cannot be swapped for a permissive alternative. Consumers
    // already hold a Statamic license to run the CMS this addon extends, and
    // this package itself is MIT.
    'statamic/cms' => 'Proprietary; inherent to a Statamic addon. Consumers license Statamic separately.',
];

$lockPath = dirname(__DIR__) . '/composer.lock';

if (! is_file($lockPath)) {
    fwrite(STDERR, "composer.lock not found — run composer install first.\n");
    exit(1);
}

$lock = json_decode((string) file_get_contents($lockPath), true, 512, JSON_THROW_ON_ERROR);

if (! is_array($lock)) {
    fwrite(STDERR, "composer.lock is not valid JSON.\n");
    exit(1);
}

/**
 * Split an SPDX expression into the individual license identifiers it offers.
 *
 * Only the "OR"/"AND" arms matter here; any arm being permissive is enough, and
 * treating "AND" the same way is deliberate — a package offered as "MIT AND
 * BSD-3-Clause" is permissive either way.
 *
 * @return list<string>
 */
function spdxArms(string $expression): array
{
    $normalised = str_replace(['(', ')'], ' ', $expression);
    $parts = preg_split('/\s+(?:OR|AND)\s+/i', $normalised) ?: [];

    return array_values(array_filter(array_map('trim', $parts), fn (string $p): bool => $p !== ''));
}

/**
 * @param  list<string>  $licenses
 */
function isPermissive(array $licenses): bool
{
    foreach ($licenses as $license) {
        foreach (spdxArms($license) as $arm) {
            if (in_array($arm, PERMISSIVE, true)) {
                return true;
            }
        }
    }

    return false;
}

$violations = [];
$checked = 0;

foreach (['packages', 'packages-dev'] as $section) {
    $packages = $lock[$section] ?? [];

    if (! is_array($packages)) {
        continue;
    }

    foreach ($packages as $package) {
        if (! is_array($package) || ! is_string($package['name'] ?? null)) {
            continue;
        }

        $name = $package['name'];
        $checked++;

        if (array_key_exists($name, EXCEPTIONS)) {
            continue;
        }

        /** @var list<string> $licenses */
        $licenses = array_values(array_filter(
            (array) ($package['license'] ?? []),
            'is_string'
        ));

        if ($licenses === []) {
            $violations[] = "{$name}: no license declared";

            continue;
        }

        if (! isPermissive($licenses)) {
            $violations[] = "{$name}: " . implode(', ', $licenses);
        }
    }
}

if ($violations !== []) {
    fwrite(STDERR, "Non-permissive licenses found:\n");

    foreach ($violations as $violation) {
        fwrite(STDERR, "  - {$violation}\n");
    }

    fwrite(STDERR, "\nAdd a justified entry to EXCEPTIONS only if the license is genuinely acceptable.\n");
    exit(1);
}

echo "All {$checked} locked packages are permissively licensed.\n";
exit(0);
