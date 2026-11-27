<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Support;

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
use Illuminate\Validation\ValidationException;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Statamic\Fields\Section;
use Statamic\Fields\Tab;

/** Edits the stored definitions, not the expanded fields that lose imports and sections. */
final class BlueprintLayout
{
    /**
     * @param  array<string, mixed>  $contents
     * @param  list<array<string, mixed>>  $fields
     * @param  list<string>  $remove
     *
     * @return array{tabs: array<string, array{sections: list<array<string, mixed>>}>}
     */
    public static function update(array $contents, array $fields, ?string $tab = null, array $remove = [], bool $replace = false): array
    {
        FieldReferences::validateContents($contents);
        $blueprint = (new Blueprint)->setContents($contents);
        /** @var array<string, array{sections: list<array<string, mixed>>}> $tabs */
        $tabs = $blueprint->tabs()->map(fn (Tab $item): array => array_replace(
            array_diff_key($item->contents(), ['fields' => true]),
            ['sections' => $item->sections()->map(fn (Section $section): array => $section->contents())->all()],
        ))->all();
        $target = $tab ?? array_key_first($tabs) ?? 'main';
        if (! preg_match('/^[A-Za-z0-9_-]+$/D', $target)) {
            throw ValidationException::withMessages(['tab' => 'tab must be a handle containing letters, digits, underscores or hyphens.']);
        }
        foreach ($remove as $selector) {
            if (str_starts_with($selector, 'import:') && ! str_contains($selector, '|')) {
                throw ValidationException::withMessages(['remove_fields' => 'Use import:{fieldset}|{prefix} to identify an import unambiguously.']);
            }
        }
        $denied = app(ResourcePolicy::class)->getDeniedFields('blueprints');
        $pending = [];
        foreach ($fields as $field) {
            $pending[self::key($field)] = $field;
        }
        foreach ($tabs as $name => &$existingTab) {
            foreach ($existingTab['sections'] as &$section) {
                /** @var list<array<string, mixed>> $definitions */
                $definitions = $section['fields'] ?? [];
                $kept = [];
                foreach ($definitions as $definition) {
                    $key = self::key($definition);
                    $handles = (new Fields([$definition]))->all()->keys()->all();
                    if (self::containsDeniedFields($definition, $denied)) {
                        $kept[] = $definition;
                        unset($pending[$key]);

                        continue;
                    }
                    if ($replace && ($tab === null || $name === $tab)) {
                        continue;
                    }
                    if ($replace && isset($pending[$key])) {
                        throw ValidationException::withMessages(['fields' => 'Replacement fields must not overwrite another tab.']);
                    }
                    if (in_array($key, $remove, true)) {
                        unset($pending[$key]);

                        continue;
                    }
                    if (isset($definition['import'])) {
                        if (array_intersect($handles, $remove) !== []) {
                            throw ValidationException::withMessages(['remove_fields' => 'An imported field cannot be removed on its own. Remove the import using ' . $key . ' or edit the fieldset.']);
                        }
                    }
                    $kept[] = $pending[$key] ?? $definition;
                    unset($pending[$key]);
                }
                $section['fields'] = $kept;
            }
            unset($section);
        }
        unset($existingTab);
        if ($pending !== [] || ! isset($tabs[$target])) {
            $tabs[$target] ??= ['display' => ucfirst($target), 'sections' => []];
            $tabs[$target]['sections'][0] ??= ['fields' => []];
            $existing = $tabs[$target]['sections'][0]['fields'] ?? [];
            $tabs[$target]['sections'][0]['fields'] = [...(is_array($existing) ? $existing : []), ...array_values($pending)];
        }
        unset($contents['fields']);
        $contents['tabs'] = $tabs;

        // Resolving before persistence catches duplicate handles introduced by imports.
        (new Blueprint)->setContents($contents)->validateUniqueHandles();

        return $contents;
    }

    /** @param array<string, mixed> $definition */
    public static function key(array $definition): string
    {
        if (is_string($definition['import'] ?? null)) {
            return 'import:' . $definition['import'] . '|' . (is_string($definition['prefix'] ?? null) ? $definition['prefix'] : '');
        }

        return is_string($definition['handle'] ?? null) ? $definition['handle'] : '';
    }

    /** @param array<array-key, mixed> $definition
     * @param  array<int, string>  $denied
     */
    public static function containsDeniedFields(array $definition, array $denied): bool
    {
        if ($denied === []) {
            return false;
        }
        /** @var Field $field */
        foreach ((new Fields([$definition]))->all() as $field) {
            if (in_array($field->handle(), $denied, true)) {
                return true;
            }
            $found = false;
            FieldReferences::mapContents($field->config(), function (array $child) use ($denied, &$found): array {
                $found = $found || self::containsDeniedFields($child, $denied);

                return $child;
            });
            if ($found) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, array<string, mixed>> */
    public static function sources(Blueprint $blueprint): array
    {
        $sources = [];
        foreach ($blueprint->tabs() as $tab => $contents) {
            /** @var Tab $contents */
            $sections = $contents->sections();
            foreach ($sections as $index => $section) {
                foreach ($section->fields()->items() as $definition) {
                    $reference = is_string($definition['field'] ?? null) ? $definition['field'] : null;
                    foreach ((new Fields([$definition]))->all() as $handle => $field) {
                        /** @var Field $field */
                        $sources[$handle] = [
                            'tab' => $tab, 'section' => $index,
                            'fieldset' => $definition['import'] ?? ($reference === null ? null : substr($reference, 0, (int) strrpos($reference, '.'))),
                            'prefix' => $field->prefix() ?? '',
                            'reference' => $reference,
                        ];
                    }
                }
            }
        }

        return $sources;
    }
}
