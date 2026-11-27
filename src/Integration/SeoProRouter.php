<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Integration;

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;
use Cboxdk\StatamicMcp\Mcp\Exceptions\ToolFailure;
use Cboxdk\StatamicMcp\Mcp\Support\ConditionalFieldsValidator;
use Cboxdk\StatamicMcp\Mcp\Support\ResultSchema;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ChecksResourceVersions;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\SanitizesFieldData;
use Cboxdk\StatamicMcp\Mcp\Tools\RouterAction;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Statamic\Contracts\Entries\Collection;
use Statamic\Contracts\Taxonomies\Taxonomy;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection as Collections;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy as Taxonomies;
use Statamic\SeoPro\Events\CollectionDefaultsSaved;
use Statamic\SeoPro\Events\TaxonomyDefaultsSaved;
use Statamic\SeoPro\Facades\Error as Errors;
use Statamic\SeoPro\Facades\Redirect as Redirects;
use Statamic\SeoPro\Fields;
use Statamic\SeoPro\Redirects\Error;
use Statamic\SeoPro\Redirects\Redirect;
use Statamic\SeoPro\Rules\UniqueRedirectUrl;
use Statamic\SeoPro\SiteDefaults\LocalizedSiteDefaults;
use Statamic\SeoPro\SiteDefaults\SiteDefaults;

#[Name('statamic-seo-pro')]
#[Description('SEO Pro defaults, redirects, 404 errors, reports and sitemap. Defaults updates change supplied fields only and never remove child entry or term SEO data. Connector and arbitrary addon settings are not exposed.')]
final class SeoProRouter extends BaseRouter
{
    use ChecksResourceVersions;
    use SanitizesFieldData;

    protected function getDomain(): string
    {
        return 'seo-pro';
    }

    protected function getTypes(): array
    {
        return ['site' => 'Site defaults', 'collection' => 'Collection defaults', 'taxonomy' => 'Taxonomy defaults',
            'redirect' => 'Redirects', 'error' => 'Recorded 404 errors', 'report' => 'Site-wide reports', 'sitemap' => 'Sitemap'];
    }

    public function discoveryKeywords(): array
    {
        return ['seo', 'redirect', '404', 'sitemap', 'search engine'];
    }

    protected function actions(): array
    {
        $record = ResultSchema::object(['record' => ResultSchema::map()], ['record']);
        $page = ResultSchema::action('entries', 'list');

        return [
            RouterAction::read('list', 'List redirects or recorded 404 errors in one site.', ['redirect', 'error'], ['resource_type', 'site'], ['limit', 'cursor'])->withContract(['seo-pro:read'], $page),
            RouterAction::read('get', 'Read defaults (site or section handle) or a redirect/error id in its site.', ['site', 'collection', 'taxonomy', 'redirect', 'error'], ['resource_type'], ['site', 'handle', 'id'])->withContract(['seo-pro:read'], $record),
            RouterAction::edit('update', 'Update supplied defaults or redirect values. Section enabled=false preserves child SEO.', ['site', 'collection', 'taxonomy', 'redirect'], ['resource_type', 'data'], ['site', 'handle', 'id', 'expected_version'])->withContract(['seo-pro:write'], $record),
            RouterAction::edit('create', 'Create a redirect; native SEO Pro also deletes matching 404 errors.', ['redirect'], ['resource_type', 'site', 'data'])->withContract(['seo-pro:write', 'seo-pro:delete'], $record),
            RouterAction::delete('delete', 'Delete one redirect or recorded 404 error.', ['redirect', 'error'], ['resource_type', 'site', 'id'], ['expected_version'])->withContract(['seo-pro:write', 'seo-pro:delete'], $record),
            RouterAction::read('list_reports', 'List site-wide SEO report summaries; does not generate reports.', ['report'], ['resource_type'], ['limit', 'cursor'])->withContract(['seo-pro:site-wide'], $page),
            RouterAction::read('get_report', 'Read one existing report without generating or upgrading it.', ['report'], ['resource_type', 'id'])->withContract(['seo-pro:site-wide'], $record),
            RouterAction::admin('generate_report', 'Queue a site-wide SEO report; native retention may delete older reports.', ['report'], ['resource_type'])->withContract(['seo-pro:site-wide', 'seo-pro:write'], $record),
            RouterAction::read('sitemap', 'Read the native site-wide sitemap. Requires unrestricted content access.', ['sitemap'], ['resource_type'], ['limit', 'cursor'])->withContract(['seo-pro:site-wide'], $page),
        ];
    }

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        return parent::defineSchema($schema) + [
            'resource_type' => JsonSchema::string()->enum(array_keys($this->getTypes()))->required(),
            'handle' => JsonSchema::string()->description('Collection or taxonomy handle for section defaults.'),
            'site' => JsonSchema::string()->description('Required for site defaults, redirects and errors; must match the addressed record.'),
            'id' => JsonSchema::string()->description('Redirect, error or report id from a list.'),
            'data' => JsonSchema::object()->description('Supplied default field values, or redirect source/destination/response_code/enabled/description. Omitted values are preserved.'),
            'expected_version' => JsonSchema::string()->description('Version from get; refuses a changed record.'),
            'limit' => JsonSchema::integer()->min(1)->max(500),
            'cursor' => JsonSchema::string(),
        ];
    }

    protected function resolveResourceHandle(array $arguments): string
    {
        $type = $this->getStringArgument($arguments, 'resource_type');

        return $type . ':' . match ($type) {
            'collection', 'taxonomy' => $this->getStringArgument($arguments, 'handle'),
            'site', 'redirect', 'error' => $this->getStringArgument($arguments, 'site'),
            default => '*',
        };
    }

    protected function authorizeAction(array $arguments): void
    {
        $type = $this->getStringArgument($arguments, 'resource_type');
        $action = $this->getStringArgument($arguments, 'action');
        if (in_array($type, ['report', 'sitemap'], true)) {
            app(SeoProReports::class)->authorize();

            return;
        }
        if (in_array($type, ['site', 'redirect', 'error'], true)) {
            $site = $this->site($arguments);
            Gate::authorize('view', Site::get($site));
        }
        if ($type === 'site') {
            Gate::authorize('edit seo site defaults');
        } elseif ($type === 'collection' || $type === 'taxonomy') {
            Gate::authorize('edit seo section defaults');
            $section = $this->section($arguments);
            $domain = $type === 'collection' ? 'entries' : 'terms';
            $mode = $action === 'update' ? 'write' : 'read';
            $policy = app(ResourcePolicy::class);
            if (! $policy->canAccess($domain, $section->handle(), $mode)
                || ! $policy->canAccess('structures', $section->handle(), $mode)) {
                throw new ToolFailure(ErrorCode::Forbidden, 'This section is outside the resource policy.');
            }
        } elseif ($action === 'list') {
            Gate::authorize('index', $type === 'redirect' ? Redirect::class : Error::class);
        } elseif ($action === 'create') {
            Gate::authorize('create', Redirect::class);
            // Redirect::save() removes matching recorded errors as part of create.
            Gate::authorize('delete seo errors');
            if (! app(ResourcePolicy::class)->canAccess('seo-pro', 'error:' . $this->site($arguments), 'write')) {
                throw new ToolFailure(ErrorCode::Forbidden, 'Matching errors are outside the write resource policy.');
            }
        } else {
            $record = $this->redirectOrError($arguments);
            if ($record === null) {
                // A spent delete still authorizes before replaying its receipt.
                Gate::authorize($type === 'redirect' ? 'delete seo redirects' : 'delete seo errors');
            } else {
                Gate::authorize($action === 'get' ? ($type === 'error' ? 'index' : 'view') : $action, $record);
            }
        }
    }

    /** @param array<string, mixed> $arguments */
    private function site(array $arguments): string
    {
        $site = $this->getStringArgument($arguments, 'site');
        if ($site === '' || ! Site::get($site) instanceof \Statamic\Sites\Site) {
            throw new ToolFailure(ErrorCode::Validation, 'Select an existing site.', 'site');
        }

        return $site;
    }

    /** @param array<string, mixed> $arguments */
    private function section(array $arguments): Collection|Taxonomy
    {
        $handle = $this->getStringArgument($arguments, 'handle');
        $record = $arguments['resource_type'] === 'collection' ? Collections::find($handle) : Taxonomies::find($handle);
        if (! $record instanceof Collection && ! $record instanceof Taxonomy) {
            throw new ToolFailure(ErrorCode::NotFound, 'Section not found.', 'handle');
        }

        return $record;
    }

    /** @param array<string, mixed> $arguments */
    private function redirectOrError(array $arguments): Redirect|Error|null
    {
        $id = $this->getStringArgument($arguments, 'id');
        if ($id === '' || preg_match('/^[a-zA-Z0-9_-]+$/D', $id) !== 1) {
            throw new ToolFailure(ErrorCode::Validation, 'Select a record id from list.', 'id');
        }
        $record = ($arguments['resource_type'] === 'redirect' ? Redirects::query() : Errors::query())
            ->where('id', $id)->where('site', $this->site($arguments))->first();
        if ($record === null && $arguments['action'] === 'delete') {
            return null;
        }
        if ((! $record instanceof Redirect && ! $record instanceof Error) || $record->site() !== $this->site($arguments)) {
            throw new ToolFailure(ErrorCode::NotFound, 'Record not found in this site.', 'id');
        }

        return $record;
    }

    protected function executeAction(array $arguments): array
    {
        if (isset($arguments['expected_version'])) {
            return $this->writeIfVersionMatches($arguments['expected_version'], 'SEO record',
                $this->resolveResourceHandle($arguments) . ':' . (in_array($arguments['resource_type'], ['redirect', 'error'], true) ? $this->getStringArgument($arguments, 'id') : ''),
                fn (): string => $this->currentResourceVersion($arguments), fn (): array => $this->dispatch($arguments));
        }

        return $this->dispatch($arguments);
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function dispatch(array $arguments): array
    {
        $type = $this->getStringArgument($arguments, 'resource_type');
        $action = $this->getStringArgument($arguments, 'action');
        if (in_array($type, ['report', 'sitemap'], true)) {
            $reports = app(SeoProReports::class);
            if ($action === 'generate_report') {
                return ['record' => $this->persistStatamic(fn (): array => $reports->generate(), [])];
            }
            if ($action === 'get_report') {
                return ['record' => $reports->get($this->getStringArgument($arguments, 'id'))];
            }
            $items = $action === 'sitemap' ? $reports->sitemap() : $reports->all();
            ['limit' => $limit, 'offset' => $offset] = $this->getPaginationArgs($arguments);

            return $this->buildPage(array_slice($items, $offset, $limit), count($items), $offset, $arguments);
        }
        if ($action === 'list') {
            $query = ($type === 'redirect' ? Redirects::query() : Errors::query())->where('site', $this->site($arguments))->orderBy('id');
            ['limit' => $limit, 'offset' => $offset] = $this->getPaginationArgs($arguments);
            $count = $query->count();
            $items = $query->offset($offset)->limit($limit)->get()->map(fn (Redirect|Error $record): array => $this->record($record))->all();

            return $this->buildPage($items, $count, $offset, $arguments);
        }
        if (in_array($type, ['site', 'collection', 'taxonomy'], true)) {
            $defaults = $type === 'site' ? SiteDefaults::in($this->site($arguments)) : $this->section($arguments);
            if (! $defaults instanceof LocalizedSiteDefaults && ! $defaults instanceof Collection && ! $defaults instanceof Taxonomy) {
                throw new ToolFailure(ErrorCode::NotFound, 'Defaults not found.');
            }
            if ($action === 'update') {
                $defaults = $this->updateDefaults($defaults, $arguments);
            }

            return ['record' => $this->defaultRecord($defaults)];
        }
        $record = $action === 'create' ? Redirects::make()->site($this->site($arguments)) : $this->redirectOrError($arguments);
        if (! $record instanceof Redirect && ! $record instanceof Error) {
            throw new ToolFailure(ErrorCode::NotFound, 'Record not found.', 'id');
        }
        if ($action === 'delete') {
            $receipt = ['id' => (string) $record->id(), 'site' => $record->site(), 'deleted' => true, 'version' => $this->record($record)['version']];
            $this->persistStatamic(function () use ($record, $arguments): void {
                $record->delete();
                if ($this->redirectOrError($arguments) !== null) {
                    throw new \RuntimeException('SEO Pro refused the deletion.');
                }
            });

            return ['record' => $receipt];
        }
        if (($action === 'create' || $action === 'update') && $record instanceof Redirect) {
            $data = is_array($arguments['data']) ? $arguments['data'] : [];
            Validator::make(['data' => $data], ['data' => ['array:source,destination,response_code,enabled,description']])->validate();
            $editable = array_flip(['source', 'destination', 'response_code', 'enabled', 'description']);
            $values = array_replace(['enabled' => true, 'response_code' => config('statamic.seo-pro.redirects.default_response_code', 301)], array_intersect_key($record->fileData(), $editable), $data);
            Validator::make($values, [
                'source' => ['required', 'string', new UniqueRedirectUrl($record->id(), $record->site())],
                'destination' => ['required', 'string'], 'response_code' => ['required', 'integer', 'in:301,302'],
                'enabled' => ['required', 'boolean'], 'description' => ['nullable', 'string'],
            ])->validate();
            $fields = Redirects::blueprint()->fields()->only(array_keys($editable))->addValues($values);
            (new ConditionalFieldsValidator)->fields($fields)->withConditionValues($values)->validate();
            $processed = $fields->process()->values();
            $record->source($processed->get('source'))->destination($processed->get('destination'))
                ->responseCode((int) $processed->get('response_code'))->enabled((bool) $processed->get('enabled'));
            if (array_key_exists('description', $data)) {
                $record->set('description', $processed->get('description'));
            }
            $record = $this->persistStatamic(function () use ($record, $editable, $action): Redirect {
                $expected = array_intersect_key($record->fileData(), $editable);
                $record->save();
                $saved = Redirects::query()->where('id', $record->id())->where('site', $record->site())->first();
                if (! $saved instanceof Redirect || $saved->site() !== $record->site()
                    || self::resourceVersion(array_intersect_key($saved->fileData(), $editable)) !== self::resourceVersion($expected)) {
                    throw new \RuntimeException('Saved redirect did not match the requested write.');
                }
                if ($action === 'create' && Errors::query()->where('url', $record->source())->where('site', $record->site())->count() !== 0) {
                    throw new \RuntimeException('Redirect saved, but matching errors could not be deleted.');
                }

                return $saved;
            });
        }

        return ['record' => $this->record($record)];
    }

    /** @return array{version: string, ...<string, mixed>} */
    private function record(Redirect|Error $record): array
    {
        $values = array_filter($record->fileData(), is_string(...), ARRAY_FILTER_USE_KEY);
        $values['id'] = (string) $record->id();
        $values['site'] = $record->site();
        if (($values['last_hit_at'] ?? null) instanceof \DateTimeInterface) {
            $values['last_hit_at'] = $values['last_hit_at']->format(DATE_ATOM);
        }

        return ['version' => self::resourceVersion($values)] + $values;
    }

    /** @return array{version: string, ...<string, mixed>} */
    private function defaultRecord(LocalizedSiteDefaults|Collection|Taxonomy $record): array
    {
        $values = $record instanceof LocalizedSiteDefaults ? $record->all() : $record->cascade('seo');
        $identity = $record instanceof LocalizedSiteDefaults ? ['site' => $record->locale()] : ['handle' => $record->handle()];

        return [...$identity, 'data' => $values, 'version' => self::resourceVersion([$identity, $values])];
    }

    /** @param array<string, mixed> $arguments */
    private function updateDefaults(LocalizedSiteDefaults|Collection|Taxonomy $record, array $arguments): LocalizedSiteDefaults|Collection|Taxonomy
    {
        $blueprint = $record instanceof LocalizedSiteDefaults ? $record->blueprint()
            : Blueprint::make()->setContents(['tabs' => ['main' => ['sections' => Fields::new()->getConfig()]]]);
        $stored = $record instanceof LocalizedSiteDefaults ? $record->all() : $record->cascade('seo');
        $stored = is_array($stored) ? $stored : ['enabled' => $stored !== false];
        $incoming = is_array($arguments['data']) ? array_filter($arguments['data'], is_string(...), ARRAY_FILTER_USE_KEY) : [];
        $unknown = array_diff(array_keys($incoming), $blueprint->fields()->all()->keys()->all());
        if ($unknown !== []) {
            throw new ToolFailure(ErrorCode::Validation, 'Unknown SEO default field.', 'data');
        }
        $sanitized = array_replace(
            $blueprint->fields()->addValues($record instanceof LocalizedSiteDefaults ? $record->values()->all() : $stored)->preProcess()->values()->all(),
            $this->sanitizeIncomingFieldData($blueprint, $incoming),
        );
        $fields = $blueprint->fields()->addValues($sanitized);
        (new ConditionalFieldsValidator)->fields($fields)->withConditionValues($sanitized)->validate();
        $values = array_replace($stored, array_intersect_key($fields->process()->values()->all(), $incoming));
        if ($record instanceof LocalizedSiteDefaults) {
            $record->set($values);

            return $this->persistStatamic(function () use ($record, $values): LocalizedSiteDefaults {
                $record->save();
                $saved = SiteDefaults::in($record->locale());
                if (! $saved instanceof LocalizedSiteDefaults || self::resourceVersion($saved->all()) !== self::resourceVersion($values)) {
                    throw new \RuntimeException('SEO defaults did not match the requested write.');
                }

                return $saved;
            });
        } else {
            $enabled = $values['enabled'] ?? true;
            unset($values['enabled']);
            $cascade = $record->cascade();
            $cascade->put('seo', $enabled === false ? false : $values);
            $record->cascade($cascade->all());
            $record = $this->persistStatamic(function () use ($record, $arguments): Collection|Taxonomy {
                $expected = self::resourceVersion([$record->cascade('seo')]);
                if ($record->save() === false) {
                    throw new \RuntimeException('Statamic refused the section defaults write.');
                }
                $saved = $this->section($arguments);
                if (self::resourceVersion([$saved->cascade('seo')]) !== $expected) {
                    throw new \RuntimeException('Section defaults did not match the requested write.');
                }

                return $saved;
            });
            if ($record instanceof Collection) {
                CollectionDefaultsSaved::dispatch($record);
            } else {
                TaxonomyDefaultsSaved::dispatch($record);
            }
        }

        return $record;
    }

    protected function currentResourceVersion(array $arguments): string
    {
        $type = $this->getStringArgument($arguments, 'resource_type');
        if ($arguments['action'] === 'create' || in_array($type, ['report', 'sitemap'], true)) {
            return '';
        }
        if ($type === 'redirect' || $type === 'error') {
            $record = $this->redirectOrError($arguments);

            return $record === null ? '' : $this->record($record)['version'];
        }
        $record = $type === 'site' ? SiteDefaults::in($this->site($arguments)) : $this->section($arguments);
        if (! $record instanceof LocalizedSiteDefaults && ! $record instanceof Collection && ! $record instanceof Taxonomy) {
            return '';
        }

        return $this->defaultRecord($record)['version'];
    }
}
