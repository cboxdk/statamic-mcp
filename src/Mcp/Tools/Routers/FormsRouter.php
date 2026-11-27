<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;
use Cboxdk\StatamicMcp\Mcp\Exceptions\ToolFailure;
use Cboxdk\StatamicMcp\Mcp\Support\FormDefinition;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ChecksResourceVersions;
use Cboxdk\StatamicMcp\Mcp\Tools\RouterAction;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Statamic\Facades\Form as Forms;
use Statamic\Facades\FormConnector;
use Statamic\Facades\Site;
use Statamic\Forms\Form;
use Statamic\Forms\Submission;
use Statamic\Statamic;

#[Name('statamic-forms')]
#[Title('Statamic Forms')]
#[Description('Forms 2 definitions and stored submissions. Form fields live in data.fields (sections or pages), not forms.* blueprints. Connections are write-only; reads show connector handles and counts, never their configuration. Submission reads require submissions:read, independently of forms:read.')]
class FormsRouter extends BaseRouter
{
    use ChecksResourceVersions;

    protected function getDomain(): string
    {
        return 'forms';
    }

    public function getTypes(): array
    {
        return [];
    }

    protected function actions(): array
    {
        return [
            RouterAction::read('list', 'List readable forms', optional: ['limit', 'cursor']),
            RouterAction::read('get', 'Read a form definition, without connector secrets or submissions', needs: ['form'], optional: ['fields']),
            RouterAction::edit('create', 'Create a form with title and optional fields, honeypot, store and connections in data', needs: ['form', 'data']),
            RouterAction::edit('update', 'Change supplied settings; data.fields replaces the definition, each data.connections handle replaces that connector\'s connection list', needs: ['form', 'data'], optional: ['expected_version']),
            RouterAction::read('list_submissions', 'Page through submissions in one site; filters accepts status, date_from and date_to (ISO 8601)', needs: ['form'], optional: ['site', 'filters', 'limit', 'cursor']),
            RouterAction::read('get_submission', 'Read one submission belonging to the named form and site', needs: ['form', 'id'], optional: ['site']),
            RouterAction::read('export', 'Export filtered submissions as cursor-paged JSON records; follow next_cursor to continue', needs: ['form'], optional: ['site', 'filters', 'limit', 'cursor']),
            RouterAction::read('summary', 'Count submissions by status in one site, respecting the same filters as list_submissions', needs: ['form'], optional: ['site', 'filters']),
            RouterAction::delete('delete_submission', 'Permanently delete one submission; does not return its field values', needs: ['form', 'id'], optional: ['site', 'expected_version']),
            RouterAction::edit('mark_spam', 'Mark a finalized submission as spam; partial submissions are refused', needs: ['form', 'id'], optional: ['site', 'expected_version']),
        ];
    }

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        return parent::defineSchema($schema) + [
            'form' => JsonSchema::string()->description('Form handle, including the new handle on create.'),
            'id' => JsonSchema::string()->description('Submission id returned by list_submissions.'),
            'site' => JsonSchema::string()->description('Submission site. Defaults to the default site; never spans sites.'),
            'data' => JsonSchema::object()->description('title, store (boolean), honeypot (field name or null), fields (stored sections/pages shape), connections (connector handle to connection list). Unknown settings are refused. Connector configuration is write-only.'),
            'filters' => JsonSchema::object()->description('status: finalized (default), partial, spam or any; date_from/date_to: inclusive ISO 8601 timestamps. No arbitrary submission-field filters.'),
            'expected_version' => JsonSchema::string()->description('Version from the last read. A different stored version refuses the write.'),
            'limit' => JsonSchema::integer()->description('Page size, default 50, maximum 500.'),
            'cursor' => JsonSchema::string()->description('next_cursor from the previous page with the same arguments.'),
        ];
    }

    protected function getRequiredTokenScope(string $action): ?TokenScope
    {
        return match ($action) {
            'list', 'get' => TokenScope::FormsRead,
            'create', 'update', 'mark_spam' => TokenScope::FormsWrite,
            'delete_submission' => TokenScope::SubmissionsDelete,
            default => TokenScope::SubmissionsRead,
        };
    }

    protected function additionalTokenScopes(string $action, array $arguments): array
    {
        return $action === 'mark_spam' ? [TokenScope::SubmissionsRead] : [];
    }

    public function availableToCaller(): bool
    {
        return $this->discoverableActions() !== [];
    }

    protected function resolveResourceHandle(array $arguments): ?string
    {
        return is_string($arguments['form'] ?? null) ? $arguments['form'] : null;
    }

    protected function getRequiredPermissions(string $action, array $arguments): array
    {
        $handle = $this->resolveResourceHandle($arguments);
        $edit = ['configure forms', 'edit forms', ...($handle ? ["edit {$handle} form"] : [])];
        $view = ['configure forms', 'view form submissions', ...($handle ? ["view {$handle} form submissions"] : [])];

        return match ($action) {
            'list' => [], // Filter each form with the native view policy.
            'get' => [[...$edit, 'configure form fields', ...$view]],
            'create' => [['configure forms', 'create forms']],
            // Exact per-setting policies run in authorizeAction, before approval/replay.
            'update' => [[...$edit, 'configure form fields']],
            'delete_submission' => [['configure forms', 'delete form submissions', ...($handle ? ["delete {$handle} form submissions"] : [])]],
            'mark_spam' => [['configure forms', 'manage form spam', ...($handle ? ["manage {$handle} form spam"] : [])], $view],
            default => [$view],
        };
    }

    protected function authorizeAction(array $arguments): void
    {
        Validator::make($arguments, [
            'form' => ['sometimes', 'string', 'regex:/^[a-zA-Z_][a-zA-Z0-9_-]*$/D'],
            'id' => ['sometimes', 'string'],
            'site' => ['sometimes', 'string'],
            'data' => ['sometimes', 'array'],
        ])->validate();
        if (isset($arguments['site']) && ! Site::get($this->getStringArgument($arguments, 'site')) instanceof \Statamic\Sites\Site) {
            throw new ToolFailure(ErrorCode::NotFound, 'Site not found.', 'site');
        }
        $action = $arguments['action'];
        if ($action === 'list') {
            return;
        }
        if ($action === 'create') {
            if ($this->isWebContext()) {
                Gate::authorize('create', Form::class);
                $form = Forms::make($this->getStringArgument($arguments, 'form'));
                foreach (array_keys(is_array($arguments['data'] ?? null) ? $arguments['data'] : []) as $key) {
                    if ($key !== 'title') {
                        Gate::authorize(match ($key) {
                            'fields' => 'editFields', 'connections' => 'editConnections', default => 'edit',
                        }, $form);
                    }
                }
            }

            return;
        }
        $form = $this->findForm($arguments);
        if ($this->isWebContext()) {
            if ($action === 'update') {
                foreach (array_keys(is_array($arguments['data'] ?? null) ? $arguments['data'] : []) as $key) {
                    Gate::authorize(match ($key) {
                        'fields' => 'editFields', 'connections' => 'editConnections', default => 'edit',
                    }, $form);
                }
            } elseif ($action === 'get') {
                Gate::authorize('view', $form);
            } else {
                Gate::authorize('view', Site::get($this->resolveSiteHandle($arguments)));
                Gate::authorize(match ($action) {
                    'delete_submission' => 'deleteSubmissions', 'mark_spam' => 'manageSpam', default => 'viewSubmissions',
                }, $form);
            }
        }
        if (isset($arguments['id'])) {
            $replayingDelete = $action === 'delete_submission' && (isset($arguments['idempotency_key']) || isset($arguments['approval_id']));
            $this->findSubmission($form, $arguments, allowMissing: $replayingDelete);
        }
    }

    protected function executeAction(array $arguments): array
    {
        $action = $arguments['action'];
        if (isset($arguments['expected_version'])) {
            return $this->writeIfVersionMatches($arguments['expected_version'], 'form record',
                $this->getStringArgument($arguments, 'form') . ':' . $this->getStringArgument($arguments, 'id'),
                fn (): string => $this->currentResourceVersion($arguments) ?? '',
                fn (): array => $this->dispatch($arguments));
        }

        return $this->dispatch($arguments);
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function dispatch(array $arguments): array
    {
        $action = $arguments['action'];
        if ($action === 'list') {
            $forms = Forms::all()->filter(fn (Form $form): bool => app(ResourcePolicy::class)->canAccess('forms', $form->handle(), 'read')
                && ($this->isCliContext() || Gate::allows('view', $form)))->sortBy->handle()->values();
            $page = $this->getPaginationArgs($arguments, 50, 500);

            return ['items' => $forms->slice($page['offset'], $page['limit'])->map(fn (Form $form): array => $this->presentForm($form))->values()->all(),
                'total' => $forms->count(), 'next_cursor' => $this->nextCursor($forms->count(), $page['offset'] + $page['limit'], $arguments)];
        }
        if ($action === 'create') {
            if (Forms::find($this->getStringArgument($arguments, 'form')) instanceof Form) {
                throw new ToolFailure(ErrorCode::Validation, 'This form already exists.', 'form');
            }
            if (! Statamic::pro() && Forms::all()->isNotEmpty()) {
                throw new ToolFailure(ErrorCode::Forbidden, 'Statamic Pro is required for more than one form.');
            }
            $form = Forms::make($this->getStringArgument($arguments, 'form'));
            $this->saveForm($form, is_array($arguments['data']) ? $arguments['data'] : [], true);

            return ['form' => $this->presentForm($this->findForm($arguments))];
        }
        $form = $this->findForm($arguments);
        if ($action === 'get' || $action === 'update') {
            if ($action === 'update') {
                $this->saveForm($form, is_array($arguments['data']) ? $arguments['data'] : []);
                $form = $this->findForm($arguments);
            }

            return ['form' => $this->presentForm($form)];
        }
        if (in_array($action, ['list_submissions', 'export', 'summary'], true)) {
            return $this->readSubmissions($form, $arguments);
        }
        $submission = $this->findSubmission($form, $arguments);
        if ($action === 'delete_submission') {
            $receipt = ['id' => (string) $submission->id(), 'form' => $form->handle(), 'site' => $submission->site()->handle(),
                'version' => $this->submissionVersion($submission), 'deleted' => true];
            $this->persistStatamic(fn () => $submission->delete(), ['stache']);

            return ['submission' => $receipt];
        }
        if ($action === 'mark_spam') {
            if ($submission->isPartial()) {
                throw new ToolFailure(ErrorCode::Validation, 'A partial submission cannot be marked as spam.');
            }
            $this->persistStatamic(fn () => $submission->markAsSpam()->save(), ['stache']);
            $submission = $this->findSubmission($form, $arguments);
        }

        return ['submission' => $this->presentSubmission($submission)];
    }

    /** @param array<string, mixed> $arguments */
    private function findForm(array $arguments): Form
    {
        $form = Forms::find($this->getStringArgument($arguments, 'form'));
        if (! $form instanceof Form) {
            throw new ToolFailure(ErrorCode::NotFound, 'Form not found.', 'form');
        }

        return $form;
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return ($allowMissing is true ? Submission|null : Submission)
     */
    private function findSubmission(Form $form, array $arguments, bool $allowMissing = false): ?Submission
    {
        $submission = $form->submission($this->getStringArgument($arguments, 'id'));
        if ($allowMissing && ! $submission instanceof Submission) {
            return null;
        }
        if (! $submission instanceof Submission || $submission->form()->handle() !== $form->handle()
            || $submission->site()->handle() !== $this->resolveSiteHandle($arguments)) {
            throw new ToolFailure(ErrorCode::NotFound, 'Submission not found in this form and site.', 'id');
        }

        return $submission;
    }

    /** @param array<string, mixed> $data */
    private function saveForm(Form $form, array $data, bool $creating = false): void
    {
        Validator::make(['data' => $data], [
            'data' => ['required', 'array:title,honeypot,store,fields,connections'],
            'data.title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'data.honeypot' => ['sometimes', 'nullable', 'string', 'regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/D'],
            'data.store' => ['sometimes', 'boolean'],
            'data.fields' => ['sometimes', 'array'],
            'data.connections' => ['sometimes', 'array'],
        ])->validate();
        if (isset($data['store']) && ! is_bool($data['store'])) {
            throw new ToolFailure(ErrorCode::Validation, 'store must be a boolean.', 'data.store');
        }
        if (is_array($data['fields'] ?? null)) {
            $denied = app(ResourcePolicy::class)->getDeniedFields('forms');
            if (app(FormDefinition::class)->containsDeniedFields($form->formFields()->contents(), $denied)) {
                throw new ToolFailure(ErrorCode::Forbidden, 'Cannot replace a form definition containing protected fields.');
            }
            $fields = app(FormDefinition::class)->validate($data['fields']);
            if (app(FormDefinition::class)->containsDeniedFields($fields->contents(), $denied)) {
                throw new ToolFailure(ErrorCode::Forbidden, 'The form definition contains protected fields.');
            }
            $form->formFields($fields->contents());
        }
        $honeypot = array_key_exists('honeypot', $data) ? ($data['honeypot'] ?? 'honeypot') : $form->honeypot();
        if ($form->formFields()->fields()->has($honeypot)) {
            throw new ToolFailure(ErrorCode::Validation, 'The honeypot must not be a real form field.', 'data.honeypot');
        }
        $connections = $form->connections();
        foreach (is_array($data['connections'] ?? null) ? $data['connections'] : [] as $handle => $values) {
            $connector = is_string($handle) ? FormConnector::find($handle)?->forForm($form) : null;
            if ($connector === null || ! is_array($values) || ! array_is_list($values)) {
                throw new ToolFailure(ErrorCode::Validation, 'Each connection must name an installed connector and provide a list.', 'data.connections');
            }
            // Third-party validation/processing may echo submitted credentials.
            // No connector exception or validation value is safe to return.
            try {
                Validator::make($values, $connector->rules())->validate();
                $connections->put($handle, $connector->process($values));
            } catch (\Throwable) {
                throw new ToolFailure(ErrorCode::Validation, 'Connector configuration is invalid. Check its requirements in the Control Panel.', 'data.connections');
            }
        }
        foreach (['title', 'honeypot', 'store'] as $key) {
            if (array_key_exists($key, $data)) {
                $form->{$key}($data[$key]);
            }
        }
        $form->connections($connections);
        $this->persistStatamic(fn () => $form->save());
    }

    /** @return array<string, mixed> */
    private function presentForm(Form $form): array
    {
        $fields = $form->formFields()->contents();
        $denied = app(ResourcePolicy::class)->getDeniedFields('forms');

        return ['handle' => $form->handle(), 'title' => $form->title(), 'honeypot' => $form->honeypot(), 'store' => $form->store(),
            'fields' => app(FormDefinition::class)->readable($fields, $denied),
            'connections' => $form->connections()->map(fn ($items, $handle): array => ['connector' => $handle, 'count' => count($items)])->values()->all(),
            'version' => $this->formVersion($form)];
    }

    private function formVersion(Form $form): string
    {
        // Legacy email connections acquire random ids on every hydration.
        // Exclude connection ids; configuration and list order remain versioned.
        $connections = $form->connections()->map(fn (array $items): array => array_map(
            fn (array $item): array => Arr::except($item, ['id']), $items,
        ))->all();

        return self::resourceVersion([$form->title(), $form->honeypot(), $form->store(), $form->data()->all(), $form->formFields()->contents(), $connections]);
    }

    private function submissionVersion(Submission $submission): string
    {
        return self::resourceVersion([$submission->id(), $submission->form()->handle(), $submission->fileData()]);
    }

    protected function filterOutputFields(array $result): array
    {
        // This path also handles stored approval/idempotency results. Apply
        // today's field policy, not the policy when the result was recorded.
        $result = app(ResourcePolicy::class)->filterFields('forms', $result);

        return app(FormDefinition::class)->filterResult($result, app(ResourcePolicy::class)->getDeniedFields('forms'));
    }

    protected function currentResourceVersion(array $arguments): ?string
    {
        if (($arguments['action'] ?? null) === 'create') {
            return null;
        }
        $form = $this->findForm($arguments);
        if (! isset($arguments['id'])) {
            return $this->formVersion($form);
        }
        $submission = $this->findSubmission($form, $arguments, allowMissing: $arguments['action'] === 'delete_submission');

        return $submission === null ? null : $this->submissionVersion($submission);
    }

    /** @return array<string, mixed> */
    private function presentSubmission(Submission $submission): array
    {
        $data = array_filter($submission->data()->all(), fn ($key): bool => is_string($key) && ! str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);

        return ['id' => (string) $submission->id(), 'form' => $submission->form()->handle(), 'site' => $submission->site()->handle(),
            'status' => $submission->status(), 'date' => $submission->date()->toIso8601String(),
            'data' => app(ResourcePolicy::class)->filterFields('forms', $data), 'version' => $this->submissionVersion($submission)];
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function readSubmissions(Form $form, array $arguments): array
    {
        $filters = $arguments['filters'] ?? [];
        Validator::make(['filters' => $filters], [
            'filters' => ['array:status,date_from,date_to'],
            'filters.status' => ['sometimes', Rule::in(['finalized', 'partial', 'spam', 'any'])],
            'filters.date_from' => ['sometimes', 'string', 'date'], 'filters.date_to' => ['sometimes', 'string', 'date', ...(is_array($filters) && isset($filters['date_from']) ? ['after_or_equal:filters.date_from'] : [])],
        ])->validate();
        $filters = is_array($filters) ? $filters : [];
        $site = $this->resolveSiteHandle($arguments);
        $query = $form->querySubmissions()->whereStatus($this->getStringArgument($filters, 'status', 'finalized'));
        // Older default-site submissions have no _site field.
        $query->where(function ($query) use ($site): void {
            $query->where('_site', $site);
            if ($site === $this->resolveSiteHandle([])) {
                $query->orWhereNull('_site');
            }
        });
        foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $operator) {
            if (is_string($filters[$key] ?? null)) {
                $query->where('date', $operator, Carbon::parse($filters[$key]));
            }
        }
        if ($arguments['action'] === 'summary') {
            return ['summary' => ['form' => $form->handle(), 'site' => $site, 'total' => (clone $query)->count(),
                'finalized' => (clone $query)->whereStatus('finalized')->count(),
                'partial' => (clone $query)->whereStatus('partial')->count(), 'spam' => (clone $query)->whereStatus('spam')->count()]];
        }
        $page = $this->getPaginationArgs($arguments, 50, 500);
        $total = (clone $query)->count();
        $items = $query->orderBy('date')->orderBy('id')->offset($page['offset'])->limit($page['limit'])->get();

        return ['items' => $items->map(fn (Submission $submission): array => $this->presentSubmission($submission))->values()->all(),
            'total' => $total, 'next_cursor' => $this->nextCursor($total, $page['offset'] + $page['limit'], $arguments)];
    }
}
