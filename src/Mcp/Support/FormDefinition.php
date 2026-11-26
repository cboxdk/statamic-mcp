<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Support;

use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;
use Cboxdk\StatamicMcp\Mcp\Exceptions\ToolFailure;
use Illuminate\Support\Facades\Validator;
use Statamic\Exceptions\FormFieldtypeNotFoundException;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Statamic\Forms\Fields\FormField;
use Statamic\Forms\Fields\FormFields;
use Statamic\Rules\Handle;
use Statamic\Statamic;

/** The stored Forms 2 shape, independent of the Control Panel's Vue ids. */
final class FormDefinition
{
    /** @param array<array-key, mixed> $definition */
    public function validate(array $definition, bool $nested = false): FormFields
    {
        Validator::make(['definition' => $definition], [
            'definition' => ['array:sections,pages'],
            'definition.sections' => [array_key_exists('pages', $definition) ? 'prohibited' : 'present', 'array', 'list'],
            'definition.pages' => ['sometimes', 'array', 'list', 'min:1'],
            'definition.pages.*' => ['array:id,display,instructions,button_label,show_previous_button,previous_page_label,rules,sections'],
            'definition.pages.*.id' => ['required', 'string', 'distinct', 'regex:/^[a-zA-Z0-9_-]+$/D'],
            'definition.pages.*.sections' => ['present', 'array', 'list'],
        ])->validate();
        $fields = new FormFields($definition);
        if (is_array($definition['pages'] ?? null) && count($definition['pages']) > 1 && ! Statamic::formsProInstalled()) {
            throw new ToolFailure(ErrorCode::Validation, 'Multiple pages require Forms Pro.', 'data.fields.pages');
        }
        $handles = [];
        foreach ($fields->sections() as $section) {
            Validator::make(['section' => $section], [
                'section' => ['array:display,instructions,fields'],
                'section.fields' => ['present', 'array', 'list'],
                'section.fields.*' => ['array:handle,field,import,prefix,config,section_behavior'],
                'section.fields.*.section_behavior' => ['sometimes', 'in:preserve,flatten'],
                'section.fields.*.handle' => ['required_without:section.fields.*.import', 'string', 'regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/D'],
                'section.fields.*.field' => ['required_without:section.fields.*.import'],
                'section.fields.*.import' => ['sometimes', 'string', 'regex:/^[a-zA-Z_][a-zA-Z0-9_-]*$/D'],
                'section.fields.*.prefix' => ['sometimes', 'string', 'regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/D'],
                'section.fields.*.config' => ['sometimes', 'array'],
                'section.fields.*.field.type' => ['sometimes', 'string'],
            ])->validate();
            foreach ($section['fields'] ?? [] as $item) {
                if (! isset($item['import']) && ! is_array($item['field'] ?? null) && ! is_string($item['field'] ?? null)) {
                    throw new ToolFailure(ErrorCode::Validation, 'A field must be a configuration object or a fieldset.handle reference.', 'data.fields');
                }
                if (is_string($item['field'] ?? null) && preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*\.[a-zA-Z_][a-zA-Z0-9_]*$/D', $item['field']) !== 1) {
                    throw new ToolFailure(ErrorCode::Validation, 'Field references must be fieldset.handle.', 'data.fields');
                }
                $resolved = new FormFields(['sections' => [['fields' => [$item]]]]);
                foreach ($resolved->fields() as $handle => $field) {
                    if (in_array($handle, $handles, true)) {
                        throw new ToolFailure(ErrorCode::Validation, 'Duplicate form field handle: ' . $handle, 'data.fields');
                    }
                    $handles[] = $handle;
                    $this->validateField(new FormField($handle, $field->config()), $nested);
                }
            }
        }
        // Force native fieldtype resolution before anything is saved.
        $fields->toBlueprint()->fields()->all();

        return $fields;
    }

    private function validateField(FormField $field, bool $nested): void
    {
        try {
            $type = $field->fieldtype();
        } catch (FormFieldtypeNotFoundException) {
            throw new ToolFailure(ErrorCode::Validation, 'Unknown form fieldtype.', 'data.fields');
        }
        if ($field->type() === 'group') {
            $children = $field->config()['fields'] ?? [];
            $childFields = $this->validate(['sections' => [['fields' => $children]]], nested: true);
            foreach ($childFields->fields() as $child) {
                if (in_array($child->type(), ['assets', 'files', 'upload', 'form_upload'], true)) {
                    throw new ToolFailure(ErrorCode::Validation, 'Uploads are not supported inside form groups.', 'data.fields');
                }
            }
        }
        $options = collect(FormField::commonFieldOptions()->items())
            ->map(function (array $item) use ($nested): array {
                if ($nested && $item['handle'] === 'handle') {
                    $item['field']['validate'] = ['required', new Handle,
                        'not_in:' . implode(',', Field::reservedHandles())];
                }

                return $item;
            })
            ->merge($type->configBlueprint()->contents()['tabs']['main']['sections'][0]['fields'])
            ->reverse()->unique('handle')->reverse()->values()->all();
        $values = [...$field->config(), 'handle' => $field->handle(), 'display' => $field->display(), 'isNew' => true];
        $config = (new Fields($options))->addValues($values)->preProcess();
        (new ConditionalFieldsValidator)->fields($config)->withConditionValues($values)->validate();
    }

    /** Expand imported/referenced fields when filtering so a denied field cannot leak through a fieldset.
     * @param  array<string, mixed>  $definition
     * @param  array<int, string>  $denied
     *
     * @return array<string, mixed>
     */
    public function readable(array $definition, array $denied): array
    {
        if ($denied === []) {
            return $definition;
        }

        return $this->filterDefinitions($definition, $denied);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<int, string>  $denied
     */
    public function containsDeniedFields(array $definition, array $denied): bool
    {
        // Compare equally expanded trees, so an unrelated import alone does
        // not prevent an update. This also finds protected nested fields.
        return $denied !== [] && $this->filterDefinitions($definition, []) !== $this->filterDefinitions($definition, $denied);
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $result
     * @param  array<int, string>  $denied
     *
     * @return array<TKey, mixed>
     */
    public function filterResult(array $result, array $denied): array
    {
        if ($denied === []) {
            return $result;
        }
        foreach ($result as $key => $value) {
            if (! is_array($value)) {
                continue;
            }
            if ($key === 'data' && isset($result['success'])) {
                $result[$key] = $this->filterResult($value, $denied);
            } elseif ($key === 'form' && is_array($value['fields'] ?? null)) {
                $value['fields'] = $this->filterDefinitions($value['fields'], $denied);
                $result[$key] = $value;
            } elseif ($key === 'items') {
                foreach ($value as &$item) {
                    if (is_array($item) && is_array($item['fields'] ?? null)) {
                        $item['fields'] = $this->filterDefinitions($item['fields'], $denied);
                    }
                }
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $node
     * @param  array<int, string>  $denied
     *
     * @return array<TKey, mixed>
     */
    private function filterDefinitions(array $node, array $denied): array
    {
        foreach ($node as $key => $value) {
            if (! is_array($value)) {
                continue;
            }
            if ($key === 'fields' && array_is_list($value)) {
                $resolved = new FormFields(['sections' => [['fields' => $value]]]);
                $node[$key] = $resolved->fields()->except($denied)->map(fn (FormField $field, string $handle): array => [
                    'handle' => $handle, 'field' => $this->filterDefinitions($field->config(), $denied),
                ])->values()->all();
            } else {
                $node[$key] = $this->filterDefinitions($value, $denied);
            }
        }

        return $node;
    }
}
