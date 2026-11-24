<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools;

use Cboxdk\StatamicMcp\Actions\ActionEffect;
use Cboxdk\StatamicMcp\Actions\Contracts\OperationStore;
use Cboxdk\StatamicMcp\Actions\Operations\IdempotentWrite;
use Cboxdk\StatamicMcp\Authorization\InstallationProfile;
use Cboxdk\StatamicMcp\Authorization\McpAuthorizer;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\BuildsPaginatedResponse;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\EnforcesResourcePolicy;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\RequiresConfirmation;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\RouterHelpers;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;

/**
 * Base router class providing action routing and audit logging.
 */
abstract class BaseRouter extends BaseStatamicTool
{
    use BuildsPaginatedResponse;
    use EnforcesResourcePolicy;
    use RequiresConfirmation;
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
            'confirmation_token' => JsonSchema::string()
                ->description('Optional token returned by a previous confirmation-required response. Provide it unchanged to confirm and execute gated actions.'),
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
            return $this->createErrorResponse($denial)->toArray();
        }

        // Confirmation check (skipped in CLI and when disabled)
        $confirmationResponse = $this->handleConfirmation($action, $arguments);
        if ($confirmationResponse) {
            return $confirmationResponse;
        }

        // Filter denied fields from input
        $arguments = $this->filterInputFields($arguments);

        // Execute the action, at most once per idempotency key
        $result = $this->isWriteAction($action) && array_key_exists('idempotency_key', $arguments)
            ? $this->executeIdempotently($action, $arguments)
            : $this->executeAction($arguments);

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

        if ($this->isCliContext()) {
            [$actor, $grant] = ['stdio', 'stdio'];
        } else {
            $token = request()->attributes->get('mcp_token');
            $user = auth()->id();
            $actor = 'user:' . ($token instanceof McpTokenData ? $token->userId : (is_scalar($user) ? (string) $user : 'anonymous'));
            $grant = $token instanceof McpTokenData ? 'token:' . $token->id : 'session';
        }
        $domain = $this->getDomain();
        $effect = match (true) {
            in_array($domain, ['structures', 'blueprints', 'users', 'system'], true) => ActionEffect::Admin,
            $action === 'delete' => ActionEffect::Delete,
            in_array($action, ['publish', 'unpublish', 'publish_working_copy'], true) => ActionEffect::Publish,
            default => ActionEffect::Edit,
        };
        $input = $arguments;
        unset($input['idempotency_key'], $input['confirmation_token']);

        $intent = IdempotentWrite::intent($key, $actor, $grant, $domain, $action, $effect,
            $this->resolveSiteHandle($arguments), $profile, $this->isCliContext() ? 'stdio' : 'http', $input);
        $outcome = (new IdempotentWrite(app(OperationStore::class), app(Encrypter::class),
            config()->integer('statamic.mcp.operations.retry_seconds', 3600)))
            ->run($intent, fn (): array => $this->executeAction($arguments), fn (): ?string => $this->writeEffectsSoFar());

        return match ($outcome['status']) {
            'executed' => $outcome['result'],
            'replayed' => ['idempotent_replay' => true] + $outcome['result'],
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
}
