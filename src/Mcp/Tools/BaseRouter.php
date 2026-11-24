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
use Cboxdk\StatamicMcp\Authorization\InstallationProfile;
use Cboxdk\StatamicMcp\Authorization\McpAuthorizer;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\BuildsPaginatedResponse;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\EnforcesResourcePolicy;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\RouterHelpers;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
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
     * Get available actions for this router.
     *
     * @return array<string, string> Action name => description mapping
     */
    abstract protected function getActions(): array;

    /**
     * Get available types/resources for this router.
     *
     * @return array<string, string> Type name => description mapping
     */
    abstract protected function getTypes(): array;

    /**
     * Execute the actual action logic.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    abstract protected function executeAction(array $arguments): array;

    /**
     * What statamic-system-schema reports for a router it has no entry for,
     * which is every tool another package registers.
     *
     * @return array{domain: string, actions: list<string>, provided_by: string}
     */
    public function catalogEntry(): array
    {
        return [
            'domain' => $this->getDomain(),
            'actions' => array_map('strval', array_keys($this->getActions())),
            'provided_by' => 'extension',
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

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        $actions = array_keys($this->getActions());
        $types = array_keys($this->getTypes());

        return [
            'action' => JsonSchema::string()
                ->description('Action to perform. See the specific tool description for available actions and their required parameters.')
                ->enum($actions)
                ->required(),
            'resource_type' => JsonSchema::string()
                ->description('Resource subtype for routers that manage multiple resource kinds. See the specific tool description for valid values and required combinations with actions.')
                ->enum($types),
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
                'Permission denied: ' . ucfirst($this->getDomain()) . ' tool is disabled for web access'
            )->toArray();
        }

        $action = is_string($arguments['action'] ?? null) ? $arguments['action'] : '';

        // Token scope and Statamic permissions (HTTP), resource policy (every
        // transport): the same decision resources ask for.
        $denial = app(McpAuthorizer::class)->denial($this->describeCall($action, $arguments));
        if ($denial !== null) {
            $this->emitExecution(Events\ExecutionDenied::class, $action, $this->freshOperationId(), 'none', hrtime(true));

            return $this->createErrorResponse($denial)->toArray();
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
            return $this->createErrorResponse('This action is not allowed by the installation profile.')->toArray();
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
        return ! $this->isCliContext() && ConfirmationActionGate::enabled()
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
                'arguments' => $this->boundInput($arguments),
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
        return match (true) {
            in_array($this->getDomain(), ['structures', 'blueprints', 'users', 'system'], true) => ActionEffect::Admin,
            $action === 'delete' => ActionEffect::Delete,
            in_array($action, ['publish', 'unpublish', 'publish_working_copy'], true) => ActionEffect::Publish,
            default => ActionEffect::Edit,
        };
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
