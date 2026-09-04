<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Concerns;

use Statamic\Contracts\Assets\AssetContainer as AssetContainerContract;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Fields\Field;

/**
 * Bridges the two forms an assets field value takes.
 *
 * Statamic *stores* an assets field as container-relative paths — a bare string
 * when `max_files` is 1, a list otherwise — and that is what this addon reads
 * back out. Everything downstream of a Control Panel form submission instead
 * expects the canonical `container::path` asset ID in a list: the file rules
 * (`mimes`, `image`, `dimensions`, `max_filesize`) resolve the value with
 * `Asset::find()`, the fieldtype's own `array`/`min`/`max` rules require a list,
 * and `Assets::process()` calls `Asset::findOrFail()` before writing back.
 *
 * The Control Panel bridges the gap in `Assets::preProcess()` when it loads the
 * form. Anything that hands stored values to the validator without that step —
 * a write whose payload came from `get`, or the read-side sweep over content
 * already on disk — has to bridge it here instead.
 */
trait ResolvesAssetIds
{
    /**
     * Normalize an assets field's value into the list of canonical IDs that
     * validation and the fieldtype pipeline expect.
     *
     * References that resolve to no asset are passed through untouched, so
     * validation reports the real problem rather than silently dropping content.
     *
     * @return array<int, mixed>
     */
    protected function normalizeAssetFieldValue(Field $field, mixed $value): array
    {
        $values = match (true) {
            is_array($value) => array_values($value),
            is_string($value) && $value !== '' => [$value],
            default => [],
        };

        if ($values === [] || ($container = $this->assetFieldContainer($field)) === null) {
            return $values;
        }

        return array_map(fn (mixed $item): mixed => $this->resolveAssetId($item, $container), $values);
    }

    /**
     * Rewrite one stored reference to its canonical `container::path` ID.
     */
    protected function resolveAssetId(mixed $reference, string $container): mixed
    {
        if (! is_string($reference) || $reference === '' || str_contains($reference, '::')) {
            return $reference;
        }

        $id = $container . '::' . ltrim($reference, '/');

        return Asset::find($id) ? $id : $reference;
    }

    /**
     * Resolve the container an assets field points at, mirroring the fieldtype's
     * own resolution: the configured container, or the only one that exists.
     */
    protected function assetFieldContainer(Field $field): ?string
    {
        $configured = $field->get('container');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $containers = AssetContainer::all();

        if ($containers->count() !== 1) {
            return null;
        }

        $only = $containers->first();

        return $only instanceof AssetContainerContract ? $only->handle() : null;
    }
}
