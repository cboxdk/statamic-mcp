<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Support;

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Statamic\Facades\Fieldset;
use Statamic\Fields\Fieldset as FieldsetRecord;

/** Check names before Statamic turns fieldset imports and references into filesystem paths. */
final class FieldReferences
{
    public static function validHandle(string $handle): bool
    {
        return preg_match('/^(?:[A-Za-z0-9_-]+::)?[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*$/D', $handle) === 1;
    }

    /**
     * @param  array<array-key, mixed>  $definitions
     * @param  list<string>  $stack
     */
    public static function validate(array $definitions, array $stack = []): void
    {
        self::validateContents(['fields' => $definitions], $stack);
    }

    /** @param array<array-key, mixed> $contents
     * @param  list<string>  $stack
     */
    public static function validateContents(array $contents, array $stack = []): void
    {
        self::mapContents($contents, function (array $definition) use ($stack): array {
            self::validateDefinition($definition, $stack);

            return $definition;
        });
    }

    /**
     * Visit only native field locations, never arbitrary defaults or option maps.
     *
     * @param  array<array-key, mixed>  $contents
     * @param  callable(array<array-key, mixed>): ?array<array-key, mixed>  $visit
     *
     * @return array<array-key, mixed>
     */
    public static function mapContents(array $contents, callable $visit): array
    {
        if (is_array($contents['fields'] ?? null)) {
            $contents['fields'] = self::mapFields($contents['fields'], $visit);
        }
        foreach (['tabs', 'sections', 'sets'] as $key) {
            if (! is_array($contents[$key] ?? null)) {
                continue;
            }
            foreach ($contents[$key] as &$child) {
                if (is_array($child)) {
                    $child = self::mapContents($child, $visit);
                }
            }
            unset($child);
        }

        return $contents;
    }

    /**
     * @param  array<array-key, mixed>  $definitions
     * @param  callable(array<array-key, mixed>): ?array<array-key, mixed>  $visit
     *
     * @return array<array-key, mixed>
     */
    public static function mapFields(array $definitions, callable $visit): array
    {
        $result = [];
        foreach ($definitions as $key => $definition) {
            if (is_array($definition)) {
                $definition = $visit($definition);
                if ($definition === null) {
                    continue;
                }
                if (is_array($definition['field'] ?? null)) {
                    $definition['field'] = self::mapContents($definition['field'], $visit);
                }
                if (is_array($definition['config'] ?? null)) {
                    if (isset($definition['import'])) {
                        foreach ($definition['config'] as &$config) {
                            if (is_array($config)) {
                                $config = self::mapContents($config, $visit);
                            }
                        }
                        unset($config);
                    } else {
                        $definition['config'] = self::mapContents($definition['config'], $visit);
                    }
                }
            }
            $result[$key] = $definition;
        }

        return array_is_list($definitions) ? array_values($result) : $result;
    }

    /** @param array<array-key, mixed> $definitions
     * @param  list<string>  $stack
     */
    private static function validateDefinition(array $definitions, array $stack): void
    {
        if (array_key_exists('import', $definitions)) {
            $name = $definitions['import'];
            if (! is_string($name) || ! self::validHandle($name)) {
                throw ValidationException::withMessages(['fields' => 'Invalid fieldset import handle.']);
            }
            if (isset($definitions['prefix']) && (! is_string($definitions['prefix']) || preg_match('/^[A-Za-z0-9_]*$/D', $definitions['prefix']) !== 1)) {
                throw ValidationException::withMessages(['fields' => 'An import prefix must contain only letters, digits and underscores.']);
            }
            self::fieldset($name, $stack);
        } elseif (isset($definitions['handle']) && is_string($definitions['field'] ?? null)) {
            $reference = $definitions['field'];
            if (! self::validHandle($reference) || ! str_contains($reference, '.')) {
                throw ValidationException::withMessages(['fields' => 'A field reference must be fieldset.field.']);
            }
            self::fieldset(substr($reference, 0, (int) strrpos($reference, '.')), $stack);
        }
    }

    public static function find(string $handle): ?FieldsetRecord
    {
        // find() is cached outside Blink. all() reparses disk and retains addon namespaces.
        /** @var Collection<array-key, FieldsetRecord> $fieldsets */
        $fieldsets = Fieldset::all();
        $fresh = $fieldsets->first(fn (FieldsetRecord $fieldset): bool => $fieldset->handle() === $handle);
        if ($fresh !== null) {
            // Native import resolution calls find(), so refresh that object too.
            Fieldset::find($handle)?->setContents($fresh->contents())->initialPath($fresh->initialPath());
        }

        return $fresh;
    }

    /** @param list<string> $stack */
    private static function fieldset(string $handle, array $stack): void
    {
        if (in_array($handle, $stack, true)) {
            throw ValidationException::withMessages(['fields' => 'Circular fieldset reference: ' . implode(' -> ', [...$stack, $handle]) . '.']);
        }
        if (! app(ResourcePolicy::class)->canAccess('blueprints', $handle, 'read')) {
            throw ValidationException::withMessages(['fields' => 'The referenced fieldset is denied by resource policy.']);
        }
        $fieldset = self::find($handle);
        if ($fieldset === null) {
            throw ValidationException::withMessages(['fields' => "Fieldset not found: {$handle}."]);
        }
        self::validateContents($fieldset->contents(), [...$stack, $handle]);
    }
}
