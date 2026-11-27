<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Prompts;

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
use Cboxdk\StatamicMcp\Authorization\InstallationProfile;
use Cboxdk\StatamicMcp\Mcp\Skills\ContentWorkflowSkill;
use Cboxdk\StatamicMcp\Mcp\Support\HandleCompletions;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Completions\CompletionResponse;
use Laravel\Mcp\Server\Contracts\Completable;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

/** Read-only workflow preparation. All embedded site data passes through a router. */
final class ContentWorkflowPrompt extends Prompt implements Completable
{
    /** @param 'write_entry'|'translate_entry'|'edit_page_builder'|'model_content' $workflow */
    public function __construct(private readonly string $workflow = 'write_entry') {}

    public const NAMES = ['write_entry', 'translate_entry', 'edit_page_builder', 'model_content'];

    public function name(): string
    {
        return $this->workflow;
    }

    public function description(): string
    {
        return match ($this->workflow) {
            'write_entry' => 'Prepare an entry create or update using the collection blueprint and its current wire formats.',
            'translate_entry' => 'Prepare a translation while preserving localization inheritance and non-text values.',
            'edit_page_builder' => 'Prepare a change to one page-builder field while preserving set ids and untouched sections.',
            'model_content' => 'Design blueprint fields or a reusable fieldset from requirements and an existing blueprint.',
        };
    }

    public function arguments(): array
    {
        $arguments = [
            new Argument('collection', 'Collection handle.', required: true),
            new Argument('blueprint', 'Existing blueprint handle in that collection; its live format specs are included.', required: true),
            new Argument('task', 'The content or change the person wants.', required: true),
        ];
        if ($this->workflow === 'model_content') {
            $arguments[] = new Argument('kind', 'blueprint or fieldset; defaults to blueprint. Saving requires an explicit admin tool call.');
        } else {
            $arguments[] = new Argument('id', 'Existing entry id. Omit only when creating a new entry.', required: $this->workflow !== 'write_entry');
            $arguments[] = new Argument('site', 'Source site handle; defaults to the default site.');
        }
        if ($this->workflow === 'translate_entry') {
            $arguments[] = new Argument('target_site', 'Site handle to translate into.', required: true);
        }
        if ($this->workflow === 'edit_page_builder') {
            $arguments[] = new Argument('field', 'Top-level page-builder field handle. Only this field and its format spec are included.', required: true);
        }

        return $arguments;
    }

    public function handle(Request $request): Response
    {
        if ($denial = app(InstallationProfile::class)->denial('guidance', 'get', writes: false)) {
            throw ValidationException::withMessages(['prompt' => $denial]);
        }
        $rules = [];
        foreach ($this->arguments() as $argument) {
            $rules[$argument->name] = [$argument->required ? 'required' : 'sometimes', 'string', 'min:1', 'max:' . ($argument->name === 'task' ? 8000 : 255)];
        }
        if ($this->workflow === 'model_content') {
            $rules['kind'][] = Rule::in(['blueprint', 'fieldset']);
        }
        if ($this->workflow === 'edit_page_builder') {
            $rules['field'][] = 'regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/D';
        }
        /** @var array<string, string> $arguments */
        $arguments = $request->validate($rules);
        $schemaArguments = [
            'action' => 'get', 'namespace' => 'collections',
            'collection' => $arguments['collection'], 'handle' => $arguments['blueprint'],
        ];
        if (isset($arguments['field'])) {
            $schemaArguments['field'] = $arguments['field'];
        }
        $blueprint = $this->read(new BlueprintsRouter, $schemaArguments);
        if (($blueprint['success'] ?? false) !== true) {
            throw ValidationException::withMessages(['blueprint' => json_encode($blueprint, JSON_THROW_ON_ERROR)]);
        }
        $context = ['arguments' => $arguments, 'blueprint' => $blueprint['data']];
        if (isset($arguments['id'])) {
            $entryArguments = ['action' => 'get', 'collection' => $arguments['collection'], 'id' => $arguments['id'], 'revision_state' => 'latest'];
            if (isset($arguments['site'])) {
                $entryArguments['site'] = $arguments['site'];
            }
            if (isset($arguments['field'])) {
                $entryArguments['fields'] = ['entry.id', 'entry.version', 'entry.blueprint', 'entry.site', 'entry.origin'];
            }
            $entry = $this->read(new EntriesRouter, $entryArguments);
            if (($entry['success'] ?? false) !== true) {
                throw ValidationException::withMessages(['id' => json_encode($entry, JSON_THROW_ON_ERROR)]);
            }
            $record = $this->entryContext($entry);
            if (($record['blueprint'] ?? null) !== $arguments['blueprint']) {
                throw ValidationException::withMessages(['blueprint' => 'The supplied blueprint does not match the entry blueprint.']);
            }
            $context['entry'] = $entry['data'];
            if (isset($arguments['field'])) {
                $context['page_builder'] = $this->pageBuilder($entryArguments, $arguments['field'], $record);
            }
        }

        // The explicit prompt is the fallback for clients without Skills. Keep
        // the workflow in one shipped file; only prompts add authorized live data.
        $response = Response::text((new ContentWorkflowSkill($this->workflow))->read('SKILL.md')
            . "\n\nCurrent authorized context (JSON):\n" . json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $limit = config()->integer('statamic.mcp.security.max_response_size', 100000);
        if ($limit > 0 && strlen(json_encode(['description' => $this->description(), 'messages' => [
            ['role' => $response->role()->value, 'content' => $response->content()->toPrompt($this)],
        ]], JSON_THROW_ON_ERROR)) > $limit) {
            throw ValidationException::withMessages(['prompt' => 'Prompt context exceeds max_response_size. Use edit_page_builder with one field, shorten the task, or read selected fields directly.']);
        }

        return $response;
    }

    /**
     * Reconstruct only the authorized projected paths, not a writable record.
     *
     * @param  array<string, mixed>  $response
     *
     * @return array<string, mixed>
     */
    private function entryContext(array $response): array
    {
        $entry = data_get($response, 'data.entry');
        if (is_array($entry)) {
            return array_filter($entry, is_string(...), ARRAY_FILTER_USE_KEY);
        }
        $fields = data_get($response, 'data.fields', []);
        $projected = [];
        if (is_array($fields)) {
            foreach ($fields as $item) {
                if (is_array($item) && is_string($item['path'] ?? null)) {
                    Arr::set($projected, substr($item['path'], 6), $item['value'] ?? null);
                }
            }
        }

        return $projected;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $record
     *
     * @return array<string, mixed>
     */
    private function pageBuilder(array $arguments, string $field, array $record): array
    {
        if (! array_key_exists('origin', $record)) {
            throw ValidationException::withMessages(['field' => 'The entry origin is not readable, so page-builder inheritance cannot be determined.']);
        }
        if (in_array($field, app(ResourcePolicy::class)->getDeniedFields('entries'), true)) {
            throw ValidationException::withMessages(['field' => 'The page-builder field is not readable.']);
        }
        $arguments['fields'] = ['entry.data.' . $field];
        $result = $this->read(new EntriesRouter, $arguments);
        if (($result['success'] ?? false) === true) {
            return ['value' => data_get($result, 'data.fields.0.value'), 'source_site' => $record['site'], 'present' => true];
        }
        if (data_get($result, 'error.code') !== 'VALIDATION' || data_get($result, 'error.field') !== 'fields') {
            throw ValidationException::withMessages(['field' => json_encode($result, JSON_THROW_ON_ERROR)]);
        }
        if (is_array($record['origin'] ?? null)) {
            $arguments['id'] = $record['origin']['id'];
            $arguments['site'] = $record['origin']['site'];
            $metadata = $this->read(new EntriesRouter, [...$arguments, 'fields' => ['entry.site', 'entry.origin']]);
            if (($metadata['success'] ?? false) !== true) {
                throw ValidationException::withMessages(['field' => 'The inherited page builder cannot be read with this access.']);
            }

            return $this->pageBuilder($arguments, $field, $this->entryContext($metadata));
        }

        return ['value' => null, 'source_site' => $record['site'], 'present' => false];
    }

    public function complete(string $argument, string $value, array $context): CompletionResponse
    {
        if (app(InstallationProfile::class)->denial('guidance', 'get', writes: false) !== null
            || ! in_array($argument, array_map(fn (Argument $a): string => $a->name, $this->arguments()), true)) {
            return CompletionResponse::empty();
        }
        if ($argument === 'kind') {
            return CompletionResponse::match(['blueprint', 'fieldset']);
        }

        return HandleCompletions::for($argument, $value, $context);
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function read(BaseRouter $router, array $arguments): array
    {
        return $router->facet('read')->execute($arguments);
    }
}
