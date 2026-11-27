<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Integration;

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;
use Cboxdk\StatamicMcp\Mcp\Exceptions\ToolFailure;
use Cboxdk\StatamicMcp\Mcp\Support\FormPageRules;
use Cboxdk\StatamicMcp\Mcp\Support\ResultSchema;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ChecksResourceVersions;
use Cboxdk\StatamicMcp\Mcp\Tools\RouterAction;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Statamic\Facades\Form as Forms;
use Statamic\Forms\Form;
use Statamic\Forms\Logic\PageLogic;

#[Name('statamic-forms-pro')]
#[Description('Forms Pro Automagic settings and page-path evaluation. Page definitions and rules are edited through statamic-forms-write data.fields. Never reads submissions or connector configuration.')]
final class FormsProRouter extends BaseRouter
{
    use ChecksResourceVersions;

    private const SETTINGS = ['automagic_form', 'automagic_form_success_heading', 'automagic_form_success_message', 'automagic_form_show_reset', 'automagic_form_reset_label'];

    protected function getDomain(): string
    {
        return 'forms-pro';
    }

    protected function getTypes(): array
    {
        return [];
    }

    public function discoveryKeywords(): array
    {
        return ['forms pro', 'automagic', 'page logic', 'multi-page form'];
    }

    protected function actions(): array
    {
        $settings = ['type' => 'object', 'properties' => ['form' => ['type' => 'string'],
            'settings' => ResultSchema::map(), 'pages' => ['type' => 'array', 'items' => ['type' => 'string']],
            'version' => ['type' => 'string']], 'additionalProperties' => false];

        return [
            RouterAction::read('get', 'Read Automagic settings and page ids.', needs: ['form'])->withContract(['forms-pro:read'], $settings),
            RouterAction::edit('update', 'Update supplied Automagic settings.', needs: ['form', 'data'], optional: ['expected_version'])->withContract(['forms-pro:write'], $settings),
            RouterAction::read('page_path', 'Evaluate native page logic against supplied field values, without saving a submission.', needs: ['form'], optional: ['data'])
                ->withContract(['forms-pro:read'], ['type' => 'object', 'properties' => ['path' => ['type' => 'array', 'items' => ['type' => 'string']]], 'additionalProperties' => false]),
        ];
    }

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        return parent::defineSchema($schema) + [
            'form' => JsonSchema::string()->description('Existing form handle.'),
            'data' => JsonSchema::anyOf([JsonSchema::object(), JsonSchema::array()->max(0)])
                ->description('update: automagic_form, automagic_form_success_heading/message, automagic_form_show_reset/reset_label. page_path: field values used by page conditions; omit for an unanswered form.'),
            'expected_version' => JsonSchema::string()->description('Version from get; a change refuses the update.'),
        ];
    }

    protected function resolveResourceHandle(array $arguments): ?string
    {
        return is_string($arguments['form'] ?? null) ? $arguments['form'] : null;
    }

    protected function authorizeAction(array $arguments): void
    {
        $form = $this->form($arguments);
        $writing = $arguments['action'] === 'update';
        Gate::authorize($writing ? 'edit' : 'view', $form);
        $policy = app(ResourcePolicy::class);
        if (! $policy->canAccess('forms', $form->handle(), $writing ? 'write' : 'read')) {
            throw new ToolFailure(ErrorCode::Forbidden, 'This form is outside the forms resource policy.');
        }
        if ($arguments['action'] === 'page_path') {
            // Evaluating a hidden condition would be an oracle for its value.
            $denied = [...$policy->getDeniedFields('forms'), ...$policy->getDeniedFields('forms-pro')];
            foreach ($form->formFields()->pages() as $page) {
                foreach ($page['rules'] ?? [] as $rule) {
                    if (FormPageRules::referencesProtectedField($rule, $denied)) {
                        throw new ToolFailure(ErrorCode::Forbidden, 'Page logic references a protected field.');
                    }
                }
            }
        }
    }

    /** @param array<string, mixed> $arguments */
    private function form(array $arguments): Form
    {
        Validator::make($arguments, ['form' => ['required', 'string', 'regex:/^[a-zA-Z_][a-zA-Z0-9_-]*$/D']])->validate();
        $form = Forms::find($this->getStringArgument($arguments, 'form'));
        if (! $form instanceof Form) {
            throw new ToolFailure(ErrorCode::NotFound, 'Form not found.', 'form');
        }

        return $form;
    }

    protected function executeAction(array $arguments): array
    {
        if (isset($arguments['expected_version'])) {
            return $this->writeIfVersionMatches($arguments['expected_version'], 'form record', $this->getStringArgument($arguments, 'form') . ':',
                fn (): string => $this->currentResourceVersion($arguments), fn (): array => $this->dispatch($arguments));
        }

        return $this->dispatch($arguments);
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function dispatch(array $arguments): array
    {
        $form = $this->form($arguments);
        if ($arguments['action'] === 'page_path') {
            $values = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];

            return ['path' => (new PageLogic($form))->path($values)];
        }
        if ($arguments['action'] === 'update') {
            $data = is_array($arguments['data']) ? $arguments['data'] : [];
            Validator::make(['data' => $data], [
                'data' => ['required', 'array:' . implode(',', self::SETTINGS)],
                'data.automagic_form' => ['sometimes', 'boolean'],
                'data.automagic_form_show_reset' => ['sometimes', 'boolean'],
                'data.automagic_form_success_heading' => ['sometimes', 'nullable', 'string', 'max:255'],
                'data.automagic_form_success_message' => ['sometimes', 'nullable', 'string'],
                'data.automagic_form_reset_label' => ['sometimes', 'nullable', 'string', 'max:255'],
            ])->validate();
            $data = app(ResourcePolicy::class)->filterFields('forms', $data);
            foreach ($data as $key => $value) {
                if (in_array($key, ['automagic_form', 'automagic_form_show_reset'], true) && ! is_bool($value)) {
                    throw new ToolFailure(ErrorCode::Validation, 'Automagic toggles must be booleans.', 'data.' . $key);
                }
                $form->set($key, $value);
            }
            $this->persistStatamic(fn () => $form->save());
            $form = $this->form($arguments);
        }

        return ['form' => $form->handle(), 'settings' => $form->data()->only(self::SETTINGS)->all(),
            'pages' => $form->formFields()->pages()->pluck('id')->all(), 'version' => $this->currentResourceVersion($arguments)];
    }

    protected function currentResourceVersion(array $arguments): string
    {
        $form = $this->form($arguments);

        return self::resourceVersion([$form->data()->all(), $form->formFields()->contents()]);
    }

    protected function filterOutputFields(array $result): array
    {
        return app(ResourcePolicy::class)->filterFields('forms', parent::filterOutputFields($result));
    }
}
