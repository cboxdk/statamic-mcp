<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Mcp\Support\ConditionalFieldsValidator;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\SanitizesFieldData;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Form as FormFacade;
use Statamic\Fields\Blueprint as BlueprintObject;
use Statamic\Forms\Form;
use Statamic\Rules\Handle;
use Statamic\Statamic;

/**
 * Form configuration: the settings the Control Panel's "Configure Form"
 * screen edits. A form's fields are its blueprint, which stays with
 * statamic-blueprints (namespace "forms") and its own scope and gate.
 *
 * Submissions are deliberately not reachable here: they are personal data
 * and need their own permission model.
 */
#[Name('statamic-forms')]
#[Title('Statamic Forms')]
#[Description('Manage Statamic forms and their settings (title, honeypot, store submissions, email notifications, and any settings addons add). Form fields live in the form\'s blueprint: use statamic-blueprints with namespace "forms". Actions: list, get, create, update, delete.')]
class FormsRouter extends BaseRouter
{
    use SanitizesFieldData;

    /**
     * Settings Statamic stores as form properties rather than data. Every
     * other setting key is an addon-registered config field.
     */
    private const CORE_SETTINGS = ['title', 'honeypot', 'store', 'email'];

    private const EMAIL_STRING_KEYS = ['to', 'cc', 'bcc', 'from', 'reply_to', 'subject', 'html', 'text', 'mailer'];

    private const EMAIL_BOOLEAN_KEYS = ['markdown', 'attachments'];

    protected function getDomain(): string
    {
        return 'forms';
    }

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        return array_merge(parent::defineSchema($schema), [
            'action' => JsonSchema::string()
                ->description(
                    'Action to perform. Required params per action: '
                    . 'list (optional: limit, offset), '
                    . 'get (handle), '
                    . 'create (data.title; optional: handle, other data settings), '
                    . 'update (handle, data), '
                    . 'delete (handle — also deletes every stored submission)'
                )
                ->enum(['list', 'get', 'create', 'update', 'delete'])
                ->required(),

            'handle' => JsonSchema::string()
                ->description('Form handle in snake_case. Required for get, update, delete. Optional for create, where it defaults to the snake_cased title. Example: "contact"'),

            'data' => JsonSchema::object()
                ->description(
                    'Form settings. update changes only the keys sent. Keys: '
                    . 'title (string), '
                    . 'honeypot (string — name of the hidden anti-spam field, must not match a blueprint field; null resets to "honeypot"), '
                    . 'store (boolean — store submissions), '
                    . 'email (array of notification objects, replaces the whole list: '
                    . 'to (required), cc, bcc, from, reply_to, subject, html, text, mailer as strings; markdown, attachments as booleans). '
                    . 'Addon-registered settings are also accepted; action "get" lists them under extra_settings.'
                ),

            'limit' => JsonSchema::integer()
                ->description('Maximum results to return (default: 50, max: 500)'),

            'offset' => JsonSchema::integer()
                ->description('Number of results to skip for pagination'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function executeAction(array $arguments): array
    {
        $action = is_string($arguments['action'] ?? null) ? $arguments['action'] : '';

        if (in_array($action, ['get', 'update', 'delete'], true)) {
            $handle = $arguments['handle'] ?? null;

            if (! is_string($handle) || $handle === '') {
                return $this->createErrorResponse("Form handle is required for {$action} action")->toArray();
            }

            // Form::find() builds a file path from the handle, so it is
            // checked before anything looks it up.
            if ($error = $this->validateHandle($handle)) {
                return $error;
            }
        }

        return match ($action) {
            'list' => $this->listForms($arguments),
            'get' => $this->getForm($arguments),
            'create' => $this->createForm($arguments),
            'update' => $this->updateForm($arguments),
            'delete' => $this->deleteForm($arguments),
            default => $this->createErrorResponse("Action {$action} not supported for forms")->toArray(),
        };
    }

    /**
     * Statamic's FormPolicy grants every form action — create, edit, delete
     * and reading the configuration — through `configure forms`. The per-form
     * `view {handle} form submissions` permission covers submissions only,
     * which this router does not expose.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<int, string|array<int, string>>
     */
    protected function getRequiredPermissions(string $action, array $arguments): array
    {
        return match ($action) {
            'list', 'get', 'create', 'update', 'delete' => ['configure forms'],
            default => [],
        };
    }

    /**
     * The policy must see the form the action acts on. For create without a
     * handle that is the one derived from the title, otherwise a caller
     * restricted to some forms could create any other by omitting it.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function resolveResourceHandle(array $arguments): ?string
    {
        $handle = $arguments['handle'] ?? null;

        if (is_string($handle) && $handle !== '') {
            return $handle;
        }

        if (($arguments['action'] ?? null) === 'create') {
            $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
            $title = $data['title'] ?? null;

            if (is_string($title) && trim($title) !== '') {
                return Str::snake(trim($title));
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function buildConfirmationDescription(string $action, array $arguments): string
    {
        if ($action === 'delete') {
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : 'unknown';

            return "Delete form '{$handle}' and every submission stored for it. This action cannot be undone.";
        }

        return parent::buildConfirmationDescription($action, $arguments);
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function listForms(array $arguments): array
    {
        $pagination = $this->getPaginationArgs($arguments, 50, 500);

        try {
            $forms = $this->allForms();
            $total = count($forms);

            $items = array_map(function (Form $form): array {
                $email = $form->email();

                return [
                    'handle' => $this->formHandle($form),
                    'title' => $form->title(),
                    'store' => $form->store(),
                    'email_notifications' => is_array($email) ? count($this->normalizeEmailList($email)) : 0,
                    'has_blueprint' => Blueprint::find('forms.' . $this->formHandle($form)) !== null,
                ];
            }, array_slice($forms, $pagination['offset'], $pagination['limit']));

            return [
                'forms' => $items,
                'pagination' => $this->buildPaginationMeta($total, $pagination['limit'], $pagination['offset']),
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to list forms: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getForm(array $arguments): array
    {
        /** @var string $handle */
        $handle = $arguments['handle'];

        $form = $this->findForm($handle);

        if ($form === null) {
            return $this->createErrorResponse("Form not found: {$handle}")->toArray();
        }

        $extraSettings = [];
        foreach ($this->extraConfigFields($handle) as $fieldHandle => $config) {
            $extraSettings[$fieldHandle] = array_filter([
                'type' => $config['type'] ?? 'text',
                'display' => $config['display'] ?? null,
                'instructions' => $config['instructions'] ?? null,
                'validate' => $config['validate'] ?? null,
                'options' => $config['options'] ?? null,
            ], fn (mixed $value): bool => $value !== null);
        }

        return [
            'form' => $this->presentForm($form) + [
                'extra_settings' => $extraSettings,
                'available_mailers' => $this->mailerNames(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function createForm(array $arguments): array
    {
        $data = $arguments['data'] ?? [];

        if (! is_array($data)) {
            return $this->createErrorResponse('data must be an object of form settings')->toArray();
        }

        /** @var array<string, mixed> $data */
        $title = $data['title'] ?? null;

        if (! is_string($title) || trim($title) === '') {
            return $this->createErrorResponse('data.title is required to create a form')->toArray();
        }

        $rawHandle = $arguments['handle'] ?? null;
        $handle = is_string($rawHandle) && $rawHandle !== '' ? $rawHandle : Str::snake(trim($title));

        if ($error = $this->validateHandle($handle)) {
            return $error;
        }

        if ($this->findForm($handle) !== null) {
            return $this->createErrorResponse("Form '{$handle}' already exists")->toArray();
        }

        // Mirrors FormsController::store(): Statamic Solo allows one form.
        if (! Statamic::pro() && count($this->allForms()) >= 1) {
            return $this->createErrorResponse('Statamic Pro is required to create more than one form')->toArray();
        }

        $form = FormFacade::make($handle);

        if (! $form instanceof Form) {
            return $this->createErrorResponse('Unsupported form implementation: ' . get_class($form))->toArray();
        }

        $error = $this->applySettings($form, $data);

        if ($error !== null) {
            return $error;
        }

        try {
            $form->save();
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to create form: {$e->getMessage()}")->toArray();
        }

        return [
            'form' => $this->presentForm($form),
            'created' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateForm(array $arguments): array
    {
        /** @var string $handle */
        $handle = $arguments['handle'];
        $data = $arguments['data'] ?? null;

        if (! is_array($data) || $data === []) {
            return $this->createErrorResponse('data is required for update action')->toArray();
        }

        /** @var array<string, mixed> $data */
        $form = $this->findForm($handle);

        if ($form === null) {
            return $this->createErrorResponse("Form not found: {$handle}")->toArray();
        }

        $error = $this->applySettings($form, $data);

        if ($error !== null) {
            return $error;
        }

        try {
            $form->save();
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to update form: {$e->getMessage()}")->toArray();
        }

        return [
            'form' => $this->presentForm($form),
            'updated_settings' => array_keys($data),
            'updated' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deleteForm(array $arguments): array
    {
        /** @var string $handle */
        $handle = $arguments['handle'];

        $form = $this->findForm($handle);

        if ($form === null) {
            return $this->createErrorResponse("Form not found: {$handle}")->toArray();
        }

        try {
            $submissions = $form->submissions()->count();

            if ($form->delete() === false) {
                return $this->createErrorResponse("Deleting form '{$handle}' was cancelled by a FormDeleting listener")->toArray();
            }
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to delete form: {$e->getMessage()}")->toArray();
        }

        return [
            'deleted' => true,
            'handle' => $handle,
            'submissions_deleted' => $submissions,
        ];
    }

    /**
     * Validate every setting in the payload, then apply them to the form.
     * Nothing is applied unless the whole payload is valid.
     *
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>|null error response, or null on success
     */
    private function applySettings(Form $form, array $data): ?array
    {
        $handle = $this->formHandle($form);
        $extraFields = $this->extraConfigFields($handle);

        $unknown = array_values(array_diff(array_keys($data), self::CORE_SETTINGS, array_keys($extraFields)));

        if ($unknown !== []) {
            return $this->createErrorResponse(
                'Unknown form setting(s): ' . implode(', ', $unknown)
                . '. Known settings: ' . implode(', ', [...self::CORE_SETTINGS, ...array_keys($extraFields)])
            )->toArray();
        }

        $errors = [];

        if (array_key_exists('title', $data)) {
            if (! is_string($data['title']) || trim($data['title']) === '') {
                $errors[] = 'title must be a non-empty string';
            }
        }

        if (array_key_exists('honeypot', $data)) {
            $honeypot = $data['honeypot'];

            if ($honeypot !== null && (! is_string($honeypot) || trim($honeypot) === '')) {
                $errors[] = 'honeypot must be a non-empty string, or null to reset it to "honeypot"';
            } elseif (is_string($honeypot) && in_array($honeypot, $this->blueprintFieldHandles($handle), true)) {
                // Statamic discards any submission that fills the honeypot,
                // so naming a real field would silently drop every one.
                $errors[] = "honeypot '{$honeypot}' is a field in the form's blueprint; every submission filling that field would be discarded as spam";
            }
        }

        if (array_key_exists('store', $data) && ! is_bool($data['store'])) {
            $errors[] = 'store must be a boolean';
        }

        $emails = null;

        if (array_key_exists('email', $data)) {
            $emails = $this->validateEmails($data['email'], $errors);
        }

        $extraData = array_intersect_key($data, $extraFields);
        $processedExtra = [];

        if ($errors === [] && $extraData !== []) {
            $result = $this->processExtraSettings($form, $extraFields, $extraData);

            if (is_string($result)) {
                $errors[] = $result;
            } else {
                $processedExtra = $result;
            }
        }

        if ($errors !== []) {
            return $this->createErrorResponse('Invalid form settings: ' . implode('; ', $errors))->toArray();
        }

        if (array_key_exists('title', $data) && is_string($data['title'])) {
            $form->title(trim($data['title']));
        }

        if (array_key_exists('honeypot', $data)) {
            // The setter stores the default name as null.
            $form->honeypot(is_string($data['honeypot']) ? trim($data['honeypot']) : 'honeypot');
        }

        if (array_key_exists('store', $data)) {
            $form->store($data['store']);
        }

        if ($emails !== null) {
            $form->email($emails);
        }

        foreach ($processedExtra as $key => $value) {
            if ($value === null) {
                $form->remove($key);
            } else {
                $form->set($key, $value);
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $errors
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function validateEmails(mixed $value, array &$errors): ?array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            $errors[] = 'email must be an array of notification objects';

            return null;
        }

        // A single notification object is accepted as a list of one, as
        // Statamic itself does when it saves.
        $list = array_key_exists('to', $value) ? [$value] : $value;

        if (! array_is_list($list)) {
            $errors[] = 'email must be an array of notification objects';

            return null;
        }

        $mailers = $this->mailerNames();
        $allowed = [...self::EMAIL_STRING_KEYS, ...self::EMAIL_BOOLEAN_KEYS];
        $emails = [];

        foreach ($list as $index => $email) {
            $path = "email.{$index}";

            if (! is_array($email)) {
                $errors[] = "{$path} must be an object";

                continue;
            }

            $unknown = array_diff(array_keys($email), $allowed);

            if ($unknown !== []) {
                $errors[] = "{$path} has unknown key(s): " . implode(', ', $unknown) . '. Allowed: ' . implode(', ', $allowed);

                continue;
            }

            $to = $email['to'] ?? null;

            if (! is_string($to) || trim($to) === '') {
                $errors[] = "{$path}.to is required";
            }

            $clean = [];

            foreach (self::EMAIL_STRING_KEYS as $key) {
                $item = $email[$key] ?? null;

                if ($item === null || $item === '') {
                    continue;
                }

                if (! is_string($item)) {
                    $errors[] = "{$path}.{$key} must be a string";

                    continue;
                }

                $clean[$key] = $item;
            }

            foreach (['html', 'text'] as $key) {
                if (isset($clean[$key]) && (str_contains($clean[$key], '..') || str_starts_with($clean[$key], '/'))) {
                    $errors[] = "{$path}.{$key} must be a view name, not a path";
                }
            }

            if (isset($clean['mailer']) && ! in_array($clean['mailer'], $mailers, true)) {
                $errors[] = "{$path}.mailer '{$clean['mailer']}' is not a configured mailer. Available: " . implode(', ', $mailers);
            }

            foreach (self::EMAIL_BOOLEAN_KEYS as $key) {
                $item = $email[$key] ?? null;

                if ($item === null) {
                    continue;
                }

                if (! is_bool($item)) {
                    $errors[] = "{$path}.{$key} must be a boolean";

                    continue;
                }

                $clean[$key] = $item;
            }

            $emails[] = $clean;
        }

        return $emails;
    }

    /**
     * Validate addon-registered settings against their own field configs,
     * the same way the Control Panel's config screen does, and return them
     * in storage format.
     *
     * @param  array<string, array<string, mixed>>  $extraFields
     * @param  array<string, mixed>  $incoming
     *
     * @return array<string, mixed>|string processed values, or an error message
     */
    private function processExtraSettings(Form $form, array $extraFields, array $incoming): array|string
    {
        $blueprint = $this->extraSettingsBlueprint($extraFields);

        try {
            $incoming = $this->sanitizeIncomingFieldData($blueprint, $incoming);

            /** @var array<string, mixed> $stored */
            $stored = $form->data()->only(array_keys($extraFields))->all();
            $merged = $this->mergeStoredDataForValidation($stored, $incoming);

            (new ConditionalFieldsValidator)
                ->fields($blueprint->fields()->addValues($merged))
                ->withConditionValues($merged)
                ->validate();

            /** @var array<string, mixed> $processed */
            $processed = $blueprint->fields()->addValues($incoming)
                ->process()->values()
                ->only(array_keys($incoming))
                ->all();

            return $processed;
        } catch (ValidationException $e) {
            $messages = [];
            foreach ($e->errors() as $field => $fieldErrors) {
                $messages[] = "{$field}: " . implode(', ', $fieldErrors);
            }

            return implode('; ', $messages);
        } catch (\Throwable $e) {
            return 'Failed to process form settings: ' . $e->getMessage();
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $extraFields
     */
    private function extraSettingsBlueprint(array $extraFields): BlueprintObject
    {
        $fields = [];
        foreach ($extraFields as $handle => $config) {
            $fields[] = ['handle' => $handle, 'field' => $config];
        }

        /** @var BlueprintObject $blueprint */
        $blueprint = Blueprint::make()->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => $fields]]]],
        ]);

        return $blueprint;
    }

    /**
     * Config fields addons registered with Form::appendConfigFields(), keyed
     * by handle. The core settings are already excluded by Statamic.
     *
     * @return array<string, array<string, mixed>>
     */
    private function extraConfigFields(string $formHandle): array
    {
        $fields = [];

        foreach (FormFacade::extraConfigFor($formHandle) as $section) {
            if (! is_array($section) || ! is_array($section['fields'] ?? null)) {
                continue;
            }

            foreach ($section['fields'] as $key => $field) {
                if (! is_array($field)) {
                    continue;
                }

                // Fields arrive keyed by handle, or as {handle, field} pairs.
                if (is_string($field['handle'] ?? null) && is_array($field['field'] ?? null)) {
                    /** @var array<string, mixed> $config */
                    $config = $field['field'];
                    $fields[$field['handle']] = $config;
                } elseif (is_string($key)) {
                    /** @var array<string, mixed> $field */
                    $fields[$key] = $field;
                }
            }
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentForm(Form $form): array
    {
        $handle = $this->formHandle($form);
        $email = $form->email();

        /** @var array<string, mixed> $extra */
        $extra = $form->data()->except(self::CORE_SETTINGS)->all();

        $blueprintExists = Blueprint::find('forms.' . $handle) !== null;

        return [
            'handle' => $handle,
            'data' => array_merge([
                'title' => $form->title(),
                'honeypot' => $form->honeypot(),
                'store' => $form->store(),
                'email' => is_array($email) ? $this->normalizeEmailList($email) : [],
            ], $extra),
            'blueprint' => [
                'namespace' => 'forms',
                'handle' => $handle,
                'exists' => $blueprintExists,
                'fields' => $this->blueprintFieldHandles($handle),
            ],
        ];
    }

    /**
     * @param  array<mixed>  $email
     *
     * @return array<int, mixed>
     */
    private function normalizeEmailList(array $email): array
    {
        return array_key_exists('to', $email) ? [$email] : array_values($email);
    }

    /**
     * @return array<int, string>
     */
    private function blueprintFieldHandles(string $formHandle): array
    {
        $blueprint = Blueprint::find('forms.' . $formHandle);

        if (! $blueprint instanceof BlueprintObject) {
            return [];
        }

        $handles = [];
        foreach ($blueprint->fields()->all()->keys() as $key) {
            if (is_string($key)) {
                $handles[] = $key;
            }
        }

        return $handles;
    }

    /**
     * @return array<int, string>
     */
    private function mailerNames(): array
    {
        $mailers = config('mail.mailers');

        return is_array($mailers) ? array_map('strval', array_keys($mailers)) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function validateHandle(string $handle): ?array
    {
        $validator = Validator::make(['handle' => $handle], ['handle' => ['required', 'string', new Handle]]);

        if ($validator->fails()) {
            return $this->createErrorResponse(
                "Invalid form handle '{$handle}': use letters, numbers and single underscores, starting with a letter"
            )->toArray();
        }

        return null;
    }

    private function formHandle(Form $form): string
    {
        $handle = $form->handle();

        return is_string($handle) ? $handle : '';
    }

    private function findForm(string $handle): ?Form
    {
        /** @var mixed $form */
        $form = FormFacade::find($handle);

        return $form instanceof Form ? $form : null;
    }

    /**
     * @return array<int, Form>
     */
    private function allForms(): array
    {
        $forms = [];

        foreach (FormFacade::all() as $form) {
            if ($form instanceof Form) {
                $forms[] = $form;
            }
        }

        return $forms;
    }

    public function getActions(): array
    {
        return [
            'list' => 'List forms with a summary of their settings',
            'get' => 'Get a form\'s settings, its blueprint fields and the addon settings it accepts',
            'create' => 'Create a form with settings',
            'update' => 'Update a form\'s settings (only the keys sent change)',
            'delete' => 'Delete a form and its stored submissions',
        ];
    }

    public function getTypes(): array
    {
        return [
            'Form' => 'Form configuration: title, honeypot, submission storage and email notifications',
        ];
    }
}
