#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Emit a deterministic CycloneDX 1.5 SBOM from composer.lock.
 *
 * Deterministic on purpose: components are sorted, and the serial number is
 * derived from the content rather than randomly generated, so sbom.json changes
 * only when the dependency graph does. CI regenerates it and fails on drift.
 */
$root = dirname(__DIR__);
$lockPath = $root . '/composer.lock';
$composerPath = $root . '/composer.json';

if (! is_file($lockPath)) {
    fwrite(STDERR, "composer.lock not found — run composer install first.\n");
    exit(1);
}

$lock = json_decode((string) file_get_contents($lockPath), true, 512, JSON_THROW_ON_ERROR);
$composer = json_decode((string) file_get_contents($composerPath), true, 512, JSON_THROW_ON_ERROR);

if (! is_array($lock) || ! is_array($composer)) {
    fwrite(STDERR, "composer.lock or composer.json is not valid JSON.\n");
    exit(1);
}

/**
 * @param  array<string, mixed>  $package
 *
 * @return array<string, mixed>
 */
function component(array $package, string $scope): array
{
    $name = is_string($package['name'] ?? null) ? $package['name'] : '';
    $version = is_string($package['version'] ?? null) ? $package['version'] : '';
    [$group, $shortName] = array_pad(explode('/', $name, 2), 2, '');

    /** @var list<array{license: array{id: string}}> $licenses */
    $licenses = [];
    foreach ((array) ($package['license'] ?? []) as $license) {
        if (is_string($license) && $license !== '') {
            $licenses[] = ['license' => ['id' => $license]];
        }
    }

    $component = [
        'type' => 'library',
        'bom-ref' => "pkg:composer/{$name}@{$version}",
        'group' => $group,
        'name' => $shortName !== '' ? $shortName : $group,
        'version' => $version,
        'purl' => "pkg:composer/{$name}@{$version}",
        'scope' => $scope,
    ];

    if ($licenses !== []) {
        $component['licenses'] = $licenses;
    }

    if (is_string($package['description'] ?? null) && $package['description'] !== '') {
        $component['description'] = $package['description'];
    }

    $reference = $package['dist']['reference'] ?? $package['source']['reference'] ?? null;

    if (is_string($reference) && $reference !== '') {
        $component['hashes'] = [['alg' => 'SHA-1', 'content' => $reference]];
    }

    return $component;
}

$components = [];

foreach (['packages' => 'required', 'packages-dev' => 'optional'] as $section => $scope) {
    foreach ((array) ($lock[$section] ?? []) as $package) {
        if (is_array($package) && is_string($package['name'] ?? null)) {
            $components[] = component($package, $scope);
        }
    }
}

// Sorted so the output does not depend on composer.lock's ordering.
usort($components, fn (array $a, array $b): int => strcmp((string) $a['purl'], (string) $b['purl']));

$rootName = is_string($composer['name'] ?? null) ? $composer['name'] : 'cboxdk/unknown';
[$rootGroup, $rootShort] = array_pad(explode('/', $rootName, 2), 2, '');

// Content-derived serial number: the same dependency set always yields the same
// SBOM, so a diff means the graph really changed.
$fingerprint = hash('sha256', json_encode($components, JSON_THROW_ON_ERROR) . $rootName);
$serial = sprintf(
    'urn:uuid:%s-%s-%s-%s-%s',
    substr($fingerprint, 0, 8),
    substr($fingerprint, 8, 4),
    substr($fingerprint, 12, 4),
    substr($fingerprint, 16, 4),
    substr($fingerprint, 20, 12)
);

$sbom = [
    'bomFormat' => 'CycloneDX',
    'specVersion' => '1.5',
    'serialNumber' => $serial,
    'version' => 1,
    'metadata' => [
        'tools' => [
            ['vendor' => 'Cbox', 'name' => 'generate-sbom', 'version' => '1.0.0'],
        ],
        'component' => [
            'type' => 'library',
            'bom-ref' => "pkg:composer/{$rootName}",
            'group' => $rootGroup,
            'name' => $rootShort !== '' ? $rootShort : $rootGroup,
            'purl' => "pkg:composer/{$rootName}",
            'licenses' => [['license' => ['id' => is_string($composer['license'] ?? null) ? $composer['license'] : 'MIT']]],
        ],
    ],
    'components' => $components,
];

$json = json_encode($sbom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

file_put_contents($root . '/sbom.json', $json . "\n");

printf("Wrote sbom.json with %d components.\n", count($components));
exit(0);
