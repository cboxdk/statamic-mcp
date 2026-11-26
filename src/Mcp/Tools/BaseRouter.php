<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools;

use Cboxdk\StatamicMcp\Actions\ActionEffect;
use Cboxdk\StatamicMcp\Actions\Events;
use Cboxdk\StatamicMcp\Actions\LifecycleEvents;
use Cboxdk\StatamicMcp\Actions\Operations\IdempotentWrite;
use Cboxdk\StatamicMcp\Actions\Operations\OperationIntent;
use Cboxdk\StatamicMcp\Approvals\Approvals;
use Cboxdk\StatamicMcp\Auth\ConfirmationActionGate;
use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Authorization\InstallationProfile;
use Cboxdk\StatamicMcp\Authorization\McpAuthorizer;
use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;
use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorResponse;
use Cboxdk\StatamicMcp\Mcp\ExtraTools;
use Cboxdk\StatamicMcp\Mcp\Servers\StatamicMcpServer;
use Cboxdk\StatamicMcp\Mcp\Support\ResultSchema;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\BuildsPaginatedResponse;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\EnforcesResourcePolicy;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\RouterHelpers;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Str;

/**
 * Base router class providing action routing and audit logging.
 */
abstract class BaseRouter extends BaseStatamicTool
{
    use BuildsPaginatedResponse;
    use EnforcesResourcePolicy;
    use RouterHelpers;

    /**
     * Get the domain this router manages.
     *
     * @return string The domain name (e.g., 'content', 'structures', 'system')
     */
    abstract protected function getDomain(): string;

    /**
     * Every action this router offers, with its effect. The one place an
     * action is classified: scopes, resource policy mode, idempotency,
     * approvals and the schema's action list all read it.
     *
     * @return list<RouterAction>
     */
    abstract protected function actions(): array;

    /** @var array<string, RouterAction>|null */
    private ?array $declaredActions = null;

    /** The declaration of an action, or null when the router has no such action. */
    public function declaredAction(string $action): ?RouterAction
    {
        if ($this->declaredActions === null) {
            $this->declaredActions = [];
            foreach ($this->actions() as $declared) {
                if (isset($this->declaredActions[$declared->name])) {
                    throw new \LogicException(static::class . " declares the action '{$declared->name}' twice.");
                }
                $this->declaredActions[$declared->name] = $declared;
            }
        }

        return $this->declaredActions[$action] ?? null;
    }

    /** @return list<RouterAction> */
    public function declaredActions(): array
    {
        $this->declaredAction('');

        return array_values($this->declaredActions ?? []);
    }

    /** @return list<string> */
    protected function actionNames(): array
    {
        return array_map(fn (RouterAction $action): string => $action->name, $this->declaredActions());
    }

    /**
     * Candidates must already satisfy object/site-level read permissions.
     * Check the read scope, profile and resource policy as well: a writer
     * without read access must not discover other handles through a typo.
     *
     * @param  iterable<string>  $candidates
     * @param  array<string, mixed>  $arguments
     */
    protected function missingResource(string $kind, string $handle, string $field = 'handle', iterable $candidates = [], array $arguments = []): ErrorResponse
    {
        $nearby = [];
        foreach ($candidates as $candidate) {
            $read = array_replace($arguments, ['action' => 'get', $field => $candidate]);
            if (app(InstallationProfile::class)->denial($this->getDomain(), 'get', $read, false) !== null
                || app(McpAuthorizer::class)->denial($this->describeCall('get', $read)) !== null) {
                continue;
            }
            $distance = levenshtein($handle, $candidate);
            if ($distance <= max(1, intdiv(strlen($handle), 3))) {
                $nearby[] = ['handle' => $candidate, 'distance' => $distance];
            }
        }
        usort($nearby, fn (array $a, array $b): int => ($a['distance'] <=> $b['distance']) ?: strcmp($a['handle'], $b['handle']));
        $handles = array_slice(array_values(array_unique(array_column($nearby, 'handle'))), 0, 3);

        return $this->createErrorResponse(
            "{$kind} not found: {$handle}", code: ErrorCode::NotFound, field: $field,
            validValues: $handles === [] ? null : $handles,
            hint: $handles === [] ? null : 'Did you mean ' . implode(', ', $handles) . '?',
        );
    }

    /**
     * Action name => description, as discovery and the schema tool show them.
     *
     * @return array<string, string>
     */
    public function getActions(): array
    {
        $actions = [];
        foreach ($this->declaredActions() as $action) {
            $actions[$action->name] = $action->summary();
        }

        return $actions;
    }

    protected function actionWrites(string $action): bool
    {
        return $this->isWriteAction($action);
    }

    /**
     * Whether the action writes. An undeclared action counts as a write, so
     * anything that slipped past the declaration check fails closed.
     */
    protected function isWriteAction(string $action): bool
    {
        return $this->declaredAction($action)?->writes() ?? true;
    }

    /**
     * Get available types/resources for this router.
     *
     * @return array<string, string> Type name => description mapping
     */
    abstract protected function getTypes(): array;

    /** @return list<string> */
    public function resourceTypes(): array
    {
        return array_map('strval', array_keys($this->getTypes()));
    }

    /**
     * Execute the actual action logic.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    abstract protected function executeAction(array $arguments): array;

    /**
     * What statamic-system-schema reports for this tool: its domain, the
     * actions and resource types it takes, every argument, and who ships it.
     *
     * @return array{domain: string, actions: list<string>, resource_types: list<string>, arguments: list<string>, provided_by: string}
     */
    public function catalogEntry(): array
    {
        $types = $this->resourceTypes();

        return [
            'domain' => $this->getDomain(),
            'actions' => $this->facetActionNames(),
            'resource_types' => array_values(array_filter($types, fn (string $type): bool => array_filter($this->facetActions(), fn (RouterAction $action): bool => $action->appliesTo($type)) !== [])),
            'arguments' => array_map('strval', array_keys($this->schema(new JsonSchemaTypeFactory))),
            'provided_by' => ExtraTools::isRegistered(static::class) ? 'extension' : 'statamic-mcp',
        ];
    }

    /**
     * Words in an intent that make statamic-system-discover recommend this tool.
     *
     * Matched like the built-in routers' keywords: each one found in the
     * lower-cased intent adds a point, and tools are ranked by points. Only
     * read for tools another package registers; empty by default, so a tool
     * that declares none is never recommended.
     *
     * @return list<string>
     */
    public function discoveryKeywords(): array
    {
        return [];
    }

    /**
     * The reason statamic-system-discover gives when it recommends this tool.
     *
     * Defaults to the first sentence of the tool's description.
     */
    public function discoveryReason(): string
    {
        $description = trim($this->description());

        return preg_match('/^.+?[.!?](?=\s|$)/s', $description, $match) === 1
            ? $match[0]
            : $description;
    }

    /**
     * Map the router's domain to a config key for shouldRegister().
     */
    protected function getToolDomain(): ?string
    {
        return $this->getDomain();
    }

    /**
     * How this router is presented: null for the whole router (stdio tests,
     * internal callers), 'read' or 'write' for the tool clients see. A client
     * gets each router as a read tool and a write tool, so read-only use can
     * be allowed by name (`*-read`) and clients read the annotations right.
     */
    private ?string $facet = null;

    /**
     * This router presented as its read or its write tool.
     *
     * @param  'read'|'write'  $facet
     */
    public function facet(string $facet): static
    {
        $clone = clone $this;
        $clone->facet = $facet;

        return $clone;
    }

    public function presentedFacet(): ?string
    {
        return $this->facet;
    }

    protected function successDataSchema(): array
    {
        if (! in_array(static::class, [...StatamicMcpServer::CORE_TOOLS, ...StatamicMcpServer::SEARCHABLE_TOOLS], true)) {
            return parent::successDataSchema();
        }

        $variants = [];
        foreach ($this->facetActions() as $action) {
            $schema = ResultSchema::action($this->getDomain(), $action->name);
            $variants[json_encode($schema, JSON_THROW_ON_ERROR)] = $schema;
        }
        if ($this->facet !== 'read') {
            $variants['truncated'] = ResultSchema::object([
                'response_truncated' => ['const' => true], 'note' => ['type' => 'string'],
            ], ['response_truncated', 'note']);
        }

        return ['anyOf' => array_values($variants)];
    }

    /**
     * Whether this caller could use the tool at all: the installation profile
     * allows its kind of action here, and over HTTP the token holds its scope.
     * tools/list shows only these; a call still reaches the rest and is refused
     * with the reason.
     */
    public function availableToCaller(): bool
    {
        if ($this->facet === null) {
            return true;
        }
        $writes = $this->facet === 'write';
        if (! app(InstallationProfile::class)->allowsAny($this->getDomain(), $writes)) {
            return false;
        }
        if ($this->isCliContext()) {
            return true;
        }
        $token = request()->attributes->get('mcp_token');
        if (! $token instanceof McpTokenData) {
            return true;
        }
        $scope = $writes ? TokenScope::writeFor($this->getDomain()) : TokenScope::tryFrom($this->getDomain() . ':read');

        return $scope !== null && app(TokenService::class)->hasScope($token, $scope);
    }

    /** Whether the facet has any action, so whether it is a tool at all. */
    public function offersFacet(string $facet): bool
    {
        return array_filter($this->declaredActions(), fn (RouterAction $action): bool => $action->writes() === ($facet === 'write')) !== [];
    }

    /** The write tool is `-admin` when its writes administer the site, `-write` when they edit content. */
    public function writeSuffix(): string
    {
        foreach ($this->declaredActions() as $action) {
            if ($action->effect === ActionEffect::Admin) {
                return 'admin';
            }
        }

        return 'write';
    }

    public function name(): string
    {
        $base = parent::name();

        return match ($this->facet) {
            null => $base,
            'read' => "{$base}-read",
            default => "{$base}-{$this->writeSuffix()}",
        };
    }

    public function title(): string
    {
        $base = parent::title();

        return match ($this->facet) {
            null => $base,
            'read' => "{$base} (read)",
            default => $this->writeSuffix() === 'admin' ? "{$base} (admin)" : "{$base} (write)",
        };
    }

    public function description(): string
    {
        $base = parent::description();
        if ($this->facet === null) {
            return $base;
        }
        $lead = $this->facet === 'read'
            ? 'Reads only; changes nothing.'
            : 'Changes the site. Actions that need a person\'s approval answer APPROVAL_REQUIRED first.';

        return trim($base . ' ' . $lead . ' Actions: ' . implode(', ', $this->facetActionNames()) . '.');
    }

    /**
     * Behaviour hints, all four explicit. Without them a client must assume a
     * tool is a destructive, open-world write and asks about every call.
     *
     * @return array<string, mixed>
     */
    public function annotations(): array
    {
        return match ($this->facet) {
            null => parent::annotations(),
            'read' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            default => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => $this->writesReachOutside()],
        };
    }

    /** Whether a write can reach outside this site, such as fetching a URL the client names. */
    protected function writesReachOutside(): bool
    {
        return false;
    }

    /** @return list<RouterAction> */
    private function facetActions(): array
    {
        return array_values(array_filter(
            $this->declaredActions(),
            fn (RouterAction $action): bool => $this->facet === null || $action->writes() === ($this->facet === 'write'),
        ));
    }

    /** @return list<string> */
    private function facetActionNames(): array
    {
        return array_map(fn (RouterAction $action): string => $action->name, $this->facetActions());
    }

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        $actions = $this->facetActions();
        $types = array_values(array_filter(
            array_map('strval', array_keys($this->getTypes())),
            fn (string $type): bool => array_filter($actions, fn (RouterAction $action): bool => $action->appliesTo($type)) !== [],
        ));

        $properties = [
            'action' => JsonSchema::string()
                ->description(implode(' ', array_map(fn (RouterAction $action): string => "{$action->name}: {$action->summary()}", $actions)))
                ->enum(array_map(fn (RouterAction $action): string => $action->name, $actions))
                ->required(),
        ];
        if ($types !== []) {
            $properties['resource_type'] = JsonSchema::string()
                ->description('The kind of resource the action works on.')
                ->enum($types)
                ->required();
        }

        return $properties + [
            'approval_id' => JsonSchema::string()
                ->description('For actions that need a person\'s approval: the approval_id from the APPROVAL_REQUIRED answer. After the person approved it in the Control Panel, repeat the same call with approval_id added. The approval covers exactly that call, once.'),
            'idempotency_key' => JsonSchema::string()
                ->description('Optional for write actions: a key you choose (8-128 characters: letters, digits, . _ : -), new for each change. Retrying with the same key and the same arguments replays the first result instead of writing again; if the first call stopped without reporting, the retry answers OUTCOME_UNKNOWN and does not write.'),
        ];
    }

    /**
     * Implementation of BaseStatamicTool's executeInternal method.
     *
     * Centralizes the web context security guard that every router needs:
     * 1. Check if tool is enabled for web access
     * 2. Check web permissions (token scopes + Statamic user permissions)
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function executeInternal(array $arguments): array
    {
        if ($this->isWebContext() && ! $this->isWebToolEnabled()) {
            return $this->createErrorResponse(
                'Permission denied: ' . ucfirst($this->getDomain()) . ' tool is disabled for web access',
                code: ErrorCode::Forbidden,
            )->toArray();
        }

        $action = is_string($arguments['action'] ?? null) ? $arguments['action'] : '';

        $declared = $this->declaredAction($action);
        if ($declared !== null && $this->facet !== null && $declared->writes() !== ($this->facet === 'write')) {
            $other = $this->facet($this->facet === 'read' ? 'write' : 'read')->name();

            return $this->createErrorResponse(
                "The {$action} action is on the {$other} tool. This tool offers: " . implode(', ', $this->facetActionNames()) . '.',
                field: 'action', validValues: $this->facetActionNames(), hint: "Use {$other} for {$action}.",
            )->toArray();
        }
        if ($declared === null) {
            return $this->createErrorResponse(
                ($action === '' ? 'action is required.' : "Unknown action '{$action}'.")
                . ' Use one of: ' . implode(', ', $this->facet === null ? $this->actionNames() : $this->facetActionNames()) . '.',
                field: 'action', validValues: $this->facet === null ? $this->actionNames() : $this->facetActionNames(),
            )->toArray();
        }
        $type = is_string($arguments['resource_type'] ?? null) ? $arguments['resource_type'] : null;
        if (! $declared->appliesTo($type)) {
            return $this->createErrorResponse(
                "The {$action} action does not apply to resource_type '{$type}'. It applies to: " . implode(', ', $declared->types) . '.',
                field: 'resource_type', validValues: $declared->types,
            )->toArray();
        }

        if (($denial = app(McpAuthorizer::class)->authenticationDenial()) !== null) {
            $this->emitExecution(Events\ExecutionDenied::class, $action, $this->freshOperationId(), 'none', hrtime(true));

            return $this->createErrorResponse($denial['message'], code: $denial['code'])->toArray();
        }

        // An action must not drop what it was asked to do: a top-level
        // `published` on create was ignored and the entry saved as a draft,
        // and a list given a filter it does not read answers unfiltered.
        if (($unknown = $this->unknownArguments($declared, $arguments)) !== []) {
            return $this->createErrorResponse(
                "The {$action} action does not take " . implode(', ', $unknown) . '. '
                . ($declared->declaresArguments() ? 'It takes: ' . implode(', ', $this->acceptedArguments($declared)) . '. ' : '')
                . ($declared->writes() ? 'Nothing was written.' : 'Nothing was read.')
                . ($declared->writes() && in_array('data', $this->acceptedArguments($declared), true) ? ' Field values and flags such as published go inside data.' : ''),
                field: $unknown[0], validValues: $this->acceptedArguments($declared),
            )->toArray();
        }
        if (($missing = $declared->missingNeeds($arguments)) !== []) {
            return $this->createErrorResponse(
                "The {$action} action needs " . implode(', ', array_map(fn (string $need): string => str_replace('|', ' or ', $need), $missing)) . '.',
                field: $missing[0],
            )->toArray();
        }

        // Token scope and Statamic permissions (HTTP), resource policy (every
        // transport): the same decision resources ask for.
        $denial = app(McpAuthorizer::class)->denial($this->describeCall($action, $arguments));
        if ($denial !== null) {
            $this->emitExecution(Events\ExecutionDenied::class, $action, $this->freshOperationId(), 'none', hrtime(true));

            return $this->createErrorResponse($denial['message'], code: $denial['code'])->toArray();
        }

        // Filter denied fields from input
        $arguments = $this->filterInputFields($arguments);

        // A gated action runs only with a person's approval (HTTP); otherwise
        // at most once per idempotency key.
        if ($this->needsApproval($action)) {
            $result = $this->executeWithApproval($action, $arguments);
        } elseif ($this->isWriteAction($action) && array_key_exists('idempotency_key', $arguments)) {
            $result = $this->executeIdempotently($action, $arguments);
        } else {
            $result = $this->observedAction($action, $this->freshOperationId(), $arguments);
        }

        // Filter denied fields from output
        return $this->filterOutputFields($result);
    }

    /**
     * Arguments given a value that the action does not take. An action that
     * declares no arguments (an extension's) takes whatever the schema
     * offers, and only its writes are checked, as before declarations.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return list<string>
     */
    private function unknownArguments(RouterAction $action, array $arguments): array
    {
        if (! $action->declaresArguments() && ! $action->writes()) {
            return [];
        }
        $accepted = array_flip($this->acceptedArguments($action));

        return array_values(array_filter(
            array_map('strval', array_keys(array_filter($arguments, fn (mixed $value): bool => $value !== null))),
            fn (string $key): bool => ! isset($accepted[$key]),
        ));
    }

    /**
     * Every argument the action takes, the shared ones included.
     *
     * @return list<string>
     */
    private function acceptedArguments(RouterAction $action): array
    {
        if (! $action->declaresArguments()) {
            return array_map('strval', array_keys($this->defineSchema(new JsonSchemaTypeFactory)));
        }

        return [
            'action',
            ...($this->getTypes() !== [] ? ['resource_type'] : []),
            ...$action->argumentNames(),
            ...($action->writes() ? ['approval_id', 'idempotency_key'] : []),
        ];
    }

    /**
     * The schema clients see: the arguments the tool's actions take. When
     * every action declares its arguments, nothing else is offered; an
     * argument an action names that the router does not define is a bug in
     * the router and fails loudly.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchemaContract $schema): array
    {
        $properties = $this->defineSchema($schema);
        $actions = $this->facetActions();

        if ($actions !== [] && array_filter($actions, fn (RouterAction $action): bool => ! $action->declaresArguments()) === []) {
            $taken = [];
            foreach ($actions as $action) {
                foreach ($this->acceptedArguments($action) as $name) {
                    if (! array_key_exists($name, $properties)) {
                        throw new \LogicException(static::class . " action '{$action->name}' takes '{$name}', which its schema does not define.");
                    }
                    $taken[$name] = true;
                }
            }

            return array_intersect_key($properties, $taken);
        }

        return $this->facet === 'read'
            ? array_diff_key($properties, array_flip(['approval_id', 'idempotency_key']))
            : $properties;
    }

    /**
     * The record's current version for a write the caller cannot tell landed,
     * or null when this router does not version that record.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function currentResourceVersion(array $arguments): ?string
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function executeIdempotently(string $action, array $arguments): array
    {
        $key = $arguments['idempotency_key'];
        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $key)) {
            return $this->createErrorResponse('idempotency_key must be 8-128 characters of letters, digits, ".", "_", ":" or "-".')->toArray();
        }
        $profile = app(InstallationProfile::class)->name();
        if (! in_array($profile, ['editor', 'operator'], true)) {
            return $this->createErrorResponse('This action is not allowed by the installation profile.', code: ErrorCode::Forbidden)->toArray();
        }

        [$actor, $grant] = $this->callIdentity();
        $intent = IdempotentWrite::intent($key, $actor, $grant, $this->getDomain(), $action, $this->actionEffect($action),
            $this->resolveSiteHandle($arguments), $profile, $this->isCliContext() ? 'stdio' : 'http', $this->boundInput($arguments));
        $outcome = app(IdempotentWrite::class)
            ->run($intent, fn (): array => $this->observedAction($action, $intent->operationId, $arguments), fn (): ?string => $this->writeEffectsSoFar());

        return match ($outcome['status']) {
            'executed' => $outcome['result'],
            'replayed' => $this->replayed($action, $intent->operationId, ['idempotent_replay' => true] + $outcome['result']),
            'reused' => $this->createErrorResponse('This idempotency_key was already used for a different request. Nothing was saved; use a new key for a new change.',
                ['code' => 'IDEMPOTENCY_KEY_REUSED'])->toArray(),
            'spent' => $this->createErrorResponse('The call that used this idempotency_key saved nothing. Send the change again with a new key.',
                ['code' => 'IDEMPOTENCY_KEY_SPENT'])->toArray(),
            'expired' => $this->createErrorResponse('The retry window for this idempotency_key has passed, so nothing was run. Read the record before sending the change with a new key.',
                ['code' => 'IDEMPOTENCY_KEY_EXPIRED'])->toArray(),
            'unknown' => $this->createErrorResponse('A call with this idempotency_key started and has not finished, or stopped without reporting. It will not run again. Read the record to see whether the change landed.',
                ['code' => 'OUTCOME_UNKNOWN', 'current_version' => $this->currentResourceVersion($arguments)])->toArray(),
        };
    }

    /** Gated actions need approval over HTTP; the local stdio operator is the person. */
    protected function needsApproval(string $action): bool
    {
        // A read changes nothing, so there is nothing for a person to approve.
        return $this->isWriteAction($action) && ! $this->isCliContext() && ConfirmationActionGate::enabled()
            && ConfirmationActionGate::gates($this->getDomain(), $action);
    }

    /**
     * Without approval_id: record the call and ask the person to approve it in
     * the Control Panel. With it: run that exact call once, if it was approved
     * and the record has not changed since.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function executeWithApproval(string $action, array $arguments): array
    {
        [$actor, $grant] = $this->callIdentity();
        if (! str_starts_with($actor, 'user:') || $actor === 'user:anonymous') {
            return $this->createErrorResponse('This action needs a person\'s approval, and the call has no Control Panel user to ask.')->toArray();
        }
        $profile = app(InstallationProfile::class)->name();
        $approvals = app(Approvals::class);
        $id = $arguments['approval_id'] ?? null;

        if ($id === null) {
            $intent = OperationIntent::forToolCall(bin2hex(random_bytes(32)), $actor, $grant, $this->getDomain(), $action,
                $this->actionEffect($action), $this->resolveSiteHandle($arguments), $profile, 'http', $this->boundInput($arguments));
            $preview = [
                'tool' => $this->name(), 'action' => $action, 'site' => $this->resolveSiteHandle($arguments),
                'description' => $this->describeForApproval($action, $arguments),
                'arguments' => self::withoutSecrets($this->boundInput($arguments)),
                'expected_version' => $this->currentResourceVersion($arguments),
            ];
            $operation = $approvals->request($intent, $preview);

            return $this->createErrorResponse(
                'This action needs a person\'s approval. Ask them to approve it in the Control Panel, then repeat the same call with approval_id. Nothing was saved.',
                ['code' => 'APPROVAL_REQUIRED', 'approval_id' => $operation->id, 'approve_url' => cp_route('statamic-mcp.approvals.index'),
                    'description' => $preview['description'], 'expires_in' => $approvals->ttlSeconds()],
            )->toArray();
        }

        if (! is_string($id)) {
            return $this->createErrorResponse('approval_id must be the string from the APPROVAL_REQUIRED answer.')->toArray();
        }
        $intent = OperationIntent::forToolCall(str_repeat('0', 64), $actor, $grant, $this->getDomain(), $action,
            $this->actionEffect($action), $this->resolveSiteHandle($arguments), $profile, 'http', $this->boundInput($arguments));
        $outcome = $approvals->execute($id, $intent, $this->currentResourceVersion($arguments),
            fn (): array => $this->observedAction($action, $id, $arguments), fn (): ?string => $this->writeEffectsSoFar());

        return match ($outcome['status']) {
            'executed' => $outcome['result'],
            'replayed' => $this->replayed($action, $id, ['approval_replay' => true] + $outcome['result']),
            'pending' => $this->createErrorResponse('Not approved yet. Nothing was saved; repeat the call once the person approved it.', ['code' => 'APPROVAL_PENDING'])->toArray(),
            'rejected' => $this->createErrorResponse('The person rejected this action. Nothing was saved.', ['code' => 'APPROVAL_REJECTED'])->toArray(),
            'stale' => $this->createErrorResponse('The record changed after the approval was asked for, so the approval no longer holds. Nothing was saved; ask again.', ['code' => 'APPROVAL_STALE'])->toArray(),
            'mismatch' => $this->createErrorResponse('This approval was for a different call. Nothing was saved; repeat exactly the approved call, or ask again.', ['code' => 'APPROVAL_MISMATCH'])->toArray(),
            'expired' => $this->createErrorResponse('This approval has expired or is unknown. Nothing was saved; ask again.', ['code' => 'APPROVAL_EXPIRED'])->toArray(),
            'spent' => $this->createErrorResponse('The approved call ran and saved nothing. The approval is used; ask again.', ['code' => 'APPROVAL_SPENT'])->toArray(),
            'unknown' => $this->createErrorResponse('The approved call started and has not finished, or stopped without reporting. It will not run again. Read the record to see whether the change landed.',
                ['code' => 'OUTCOME_UNKNOWN', 'current_version' => $this->currentResourceVersion($arguments)])->toArray(),
        };
    }

    /**
     * Run the handler between ExecutionStarted and its terminal event. Started
     * is emitted only here, after authorization and any approval or
     * idempotency claim, so a refused call never looks like an execution.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function observedAction(string $action, string $operationId, array $arguments): array
    {
        $start = hrtime(true);
        // One execution id ties started to its terminal event; observers pair them on it.
        $execution = (string) Str::uuid();
        $this->emitExecution(Events\ExecutionStarted::class, $action, $operationId, 'none', $start, $execution);
        try {
            $result = $this->executeAction($arguments);
        } catch (\Throwable $error) {
            $this->emitExecution(Events\ExecutionFailed::class, $action, $operationId, $this->writeEffectsSoFar() ?? 'none', $start, $execution);
            throw $error;
        }
        $effects = $this->writeEffectsSoFar() ?? 'none';
        $this->emitExecution(($result['success'] ?? true) === false ? Events\ExecutionFailed::class : Events\ExecutionCompleted::class,
            $action, $operationId, $effects, $start, $execution);

        return $result;
    }

    /**
     * A stored result handed back again: no new write, so no Started/Completed.
     *
     * @param  array<string, mixed>  $result
     *
     * @return array<string, mixed>
     */
    private function replayed(string $action, string $operationId, array $result): array
    {
        $this->emitExecution(Events\ExecutionReplayed::class, $action, $operationId, 'none', hrtime(true));

        return $result;
    }

    /** @param  class-string<Events\ExecutionEvent>  $event */
    private function emitExecution(string $event, string $action, string $operationId, string $effects, int $start, ?string $execution = null): void
    {
        $domain = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($this->getDomain())), '-') ?: 'tool';
        $outcome = match ($event) {
            Events\ExecutionStarted::class => 'started',
            Events\ExecutionCompleted::class => 'completed',
            Events\ExecutionDenied::class => 'denied',
            Events\ExecutionFailed::class => 'failed',
            default => 'replayed',
        };
        try {
            $instance = new $event((string) Str::uuid(), $operationId, 'statamic-mcp/' . $domain . '/' . ($action !== '' ? $action : 'unknown'), 1,
                $this->isCliContext() ? 'stdio' : 'http', $outcome, $effects, (hrtime(true) - $start) / 1_000_000, gmdate('Y-m-d\TH:i:s\Z'),
                executionId: $execution ?? (string) Str::uuid());
        } catch (\Throwable) {
            return;
        }
        LifecycleEvents::dispatch($instance);
    }

    private function freshOperationId(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** @return array{0: string, 1: string} actor and grant, never a credential */
    private function callIdentity(): array
    {
        if ($this->isCliContext()) {
            return ['stdio', 'stdio'];
        }
        $token = request()->attributes->get('mcp_token');
        $user = auth()->id();
        $actor = 'user:' . ($token instanceof McpTokenData ? $token->userId : (is_scalar($user) ? (string) $user : 'anonymous'));

        return [$actor, $token instanceof McpTokenData ? 'token:' . $token->id : 'session'];
    }

    private function actionEffect(string $action): ActionEffect
    {
        return $this->declaredAction($action)->effect ?? ActionEffect::Edit;
    }

    /**
     * The arguments an approval or idempotency key binds: the call itself.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function boundInput(array $arguments): array
    {
        unset($arguments['idempotency_key'], $arguments['approval_id']);

        return $arguments;
    }

    /**
     * The arguments as the approval record stores and the Control Panel shows
     * them, with secrets such as a new password replaced. The approval stays
     * bound to the full input through the intent's hash.
     *
     * @param  array<array-key, mixed>  $arguments
     *
     * @return array<array-key, mixed>
     */
    private static function withoutSecrets(array $arguments): array
    {
        foreach ($arguments as $key => $value) {
            if (is_string($key) && preg_match('/password|secret|token|api_?key/i', $key) === 1 && $value !== null) {
                $arguments[$key] = '[hidden]';
            } elseif (is_array($value)) {
                $arguments[$key] = self::withoutSecrets($value);
            }
        }

        return $arguments;
    }

    /** @param  array<string, mixed>  $arguments */
    protected function describeForApproval(string $action, array $arguments): string
    {
        $domain = Str::singular($this->getDomain());
        $handle = $arguments['handle'] ?? $arguments['slug'] ?? $arguments['id'] ?? 'unknown';
        $handle = is_string($handle) ? $handle : 'unknown';
        $context = '';
        foreach (['collection' => 'collection', 'taxonomy' => 'taxonomy', 'namespace' => 'namespace', 'container' => 'container'] as $key => $label) {
            if (isset($arguments[$key]) && is_string($arguments[$key])) {
                $context = " in {$label} '{$arguments[$key]}'";
                break;
            }
        }

        return match ($action) {
            'delete' => "Delete {$domain} '{$handle}'{$context}. This cannot be undone.",
            default => ucfirst(str_replace('_', ' ', $action)) . " {$domain} '{$handle}'{$context}.",
        };
    }
}
