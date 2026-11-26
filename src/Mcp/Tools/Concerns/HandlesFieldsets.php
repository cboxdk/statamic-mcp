<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Concerns;

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;
use Cboxdk\StatamicMcp\Mcp\Support\BlueprintLayout;
use Cboxdk\StatamicMcp\Mcp\Support\FieldFormatSpec;
use Cboxdk\StatamicMcp\Mcp\Support\FieldReferences;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Statamic\Facades\Fieldset;
use Statamic\Facades\Form;
use Statamic\Facades\Nav;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Fieldset as FieldsetRecord;
use Statamic\Fields\FieldtypeRepository;

/** The reusable field definitions managed by the blueprints router. */
trait HandlesFieldsets
{
    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function listFieldsets(array $arguments): array
    {
        /** @var Collection<string, FieldsetRecord> $all */
        $all = Fieldset::all();
        $fieldsets = $all->filter(fn (FieldsetRecord $fieldset): bool => app(ResourcePolicy::class)
            ->canAccess('blueprints', (string) $fieldset->handle(), 'read'))
            ->sortBy(fn (FieldsetRecord $fieldset): ?string => $fieldset->handle())->values();
        ['offset' => $offset, 'limit' => $limit] = $this->getPaginationArgs($arguments);
        $items = $fieldsets->slice($offset, $limit)->map(fn (FieldsetRecord $fieldset): array => [
            'handle' => $fieldset->handle(), 'title' => $fieldset->title(),
            'version' => self::resourceVersion($fieldset->contents()),
        ])->values()->all();

        return $this->buildPage($items, $fieldsets->count(), $offset, $arguments);
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function fieldsetAction(array $arguments): array
    {
        $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
        if (! FieldReferences::validHandle($handle) || str_starts_with($handle, 'vendor.')) {
            return $this->createErrorResponse('Invalid fieldset handle.', field: 'handle')->toArray();
        }
        $action = $arguments['action'];
        $fieldset = FieldReferences::find($handle);
        if ($action === 'create_fieldset') {
            if ($fieldset !== null) {
                return $this->createErrorResponse('Fieldset already exists: ' . $handle)->toArray();
            }
            if (str_contains($handle, '::')) {
                return $this->createErrorResponse('Namespaced fieldsets belong to addons; only existing ones can be updated.')->toArray();
            }
            $fieldset = Fieldset::make($handle);
            $fieldset->setContents(['title' => Str::title(str_replace('_', ' ', $handle))]);
        } elseif ($fieldset === null) {
            return $this->missingResource('Fieldset', $handle, arguments: $arguments)->toArray();
        }

        if ($action === 'get_fieldset') {
            return ['fieldset' => $this->fieldsetRecord($fieldset)];
        }
        if ($action === 'delete_fieldset') {
            if (! $fieldset->isDeletable()) {
                return $this->createErrorResponse('Namespaced fieldsets cannot be deleted.')->toArray();
            }
            if ($this->fieldsetIsUsed($fieldset)) {
                return $this->createErrorResponse('This fieldset is still imported or referenced. Remove its uses before deleting it.', code: ErrorCode::Validation)->toArray();
            }
            $record = $this->fieldsetRecord($fieldset);
            $this->persistStatamic(fn () => $fieldset->delete(), ['stache']);

            return ['fieldset' => $record, 'deleted' => true];
        }

        $fieldset = clone $fieldset;
        $contents = $fieldset->contents();
        if (is_string($arguments['title'] ?? null)) {
            $contents['title'] = $arguments['title'];
        }
        $input = is_array($arguments['fields'] ?? null) ? $arguments['fields'] : [];
        FieldReferences::validate($input, [$handle]);
        $validation = $this->validateFields($input);
        if (($validation['success'] ?? true) === false) {
            return $validation;
        }
        /** @var list<array<string, mixed>> $fields */
        $fields = $validation['validated'];
        /** @var list<string> $remove */
        $remove = is_array($arguments['remove_fields'] ?? null) ? $arguments['remove_fields'] : [];
        $hadSections = isset($contents['sections']);
        $layout = BlueprintLayout::update(['tabs' => ['main' => [
            'sections' => $contents['sections'] ?? [['fields' => $contents['fields'] ?? []]],
        ]]], $fields, remove: $remove, replace: ($arguments['replace_fields'] ?? false) === true);
        $sections = $layout['tabs']['main']['sections'];
        if ($hadSections) {
            $contents['sections'] = $sections;
        } else {
            $contents['fields'] = $sections[0]['fields'];
        }
        FieldReferences::validateContents($contents, [$handle]);
        $fieldset->setContents($contents);
        $this->persistStatamic(fn () => $fieldset->save(), ['stache']);

        return ['fieldset' => $this->fieldsetRecord($fieldset)];
    }

    /** @return array<string, mixed> */
    private function fieldsetRecord(FieldsetRecord $fieldset): array
    {
        FieldReferences::validateContents($fieldset->contents(), [(string) $fieldset->handle()]);
        $spec = new FieldFormatSpec;
        /** @var Collection<string, Field> $fields */
        $fields = $fieldset->fields()->all();

        return [
            'handle' => $fieldset->handle(), 'title' => $fieldset->title(),
            'version' => self::resourceVersion($fieldset->contents()),
            'contents' => $this->visibleDefinitions($fieldset->contents()),
            'fields' => $fields->map(fn (Field $field): array => $this->describeField($field, true, $spec))->all(),
        ];
    }

    private function fieldsetIsUsed(FieldsetRecord $fieldset): bool
    {
        /** @var FieldsetRecord $candidate */
        foreach (Fieldset::all() as $candidate) {
            if ($this->referencesFieldset($candidate->contents(), (string) $fieldset->handle())) {
                return true;
            }
        }
        // Native importedBy omits unattached blueprints, navigation and Forms 2 fields.
        $blueprints = $this->collectAllBlueprints(filterPolicy: false)
            ->concat(\Statamic\Facades\Blueprint::in(''))
            ->concat(Nav::all()->map->blueprint())
            ->concat(Form::all()->map->blueprint());
        foreach (\Statamic\Facades\Blueprint::getAdditionalNamespaces()->keys() as $namespace) {
            if (is_string($namespace)) {
                $blueprints = $blueprints->concat(\Statamic\Facades\Blueprint::in($namespace));
            }
        }
        foreach ($blueprints as $blueprint) {
            if ($blueprint instanceof Blueprint && $this->referencesFieldset($blueprint->contents(), (string) $fieldset->handle())) {
                return true;
            }
        }

        return false;
    }

    /** @param array<array-key, mixed> $contents */
    private function referencesFieldset(array $contents, string $handle): bool
    {
        $found = false;
        FieldReferences::mapContents($contents, function (array $definition) use ($handle, &$found): array {
            $reference = $definition['field'] ?? null;
            if (($definition['import'] ?? null) === $handle || (is_string($reference)
                && substr($reference, 0, (int) strrpos($reference, '.')) === $handle)) {
                $found = true;
            }

            return $definition;
        });

        return $found;
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function listFieldtypes(array $arguments): array
    {
        $repository = app(FieldtypeRepository::class);
        $handles = $repository->handles()->sort()->values();
        if (isset($arguments['handle'])) {
            $handles = $handles->filter(fn (string $handle): bool => $handle === $arguments['handle'])->values();
        }
        ['offset' => $offset, 'limit' => $limit] = $this->getPaginationArgs($arguments);
        $items = $handles->slice($offset, $limit)->map(function (string $handle) use ($repository): array {
            $type = $repository->find($handle);

            return ['handle' => $handle, 'title' => $type->title(),
                'config_fields' => $type->configFields()->all()->map(fn (Field $field): array => [
                    'handle' => $field->handle(), 'config' => $field->config(),
                ])->all(),
            ];
        })->values()->all();

        return $this->buildPage($items, $handles->count(), $offset, $arguments);
    }
}
