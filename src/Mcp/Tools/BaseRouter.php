<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools;

use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\BuildsPaginatedResponse;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\EnforcesResourcePolicy;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\RequiresConfirmation;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\RouterHelpers;
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

        if ($this->isWebContext()) {
            $permissionError = $this->checkWebPermissions($action, $arguments);
            if ($permissionError) {
                return $permissionError;
            }
        }

        // Resource policy check (applies in ALL contexts — site-wide admin policy)
        $resourceError = $this->checkResourceAccess($action, $arguments);
        if ($resourceError) {
            return $resourceError;
        }

        // Confirmation check (skipped in CLI and when disabled)
        $confirmationResponse = $this->handleConfirmation($action, $arguments);
        if ($confirmationResponse) {
            return $confirmationResponse;
        }

        // Filter denied fields from input
        $arguments = $this->filterInputFields($arguments);

        // Execute the action
        $result = $this->executeAction($arguments);

        // Filter denied fields from output
        return $this->filterOutputFields($result);
    }
}
