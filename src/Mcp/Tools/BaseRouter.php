<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools;

use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\RouterHelpers;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;

/**
 * Base router class providing action routing and audit logging.
 */
abstract class BaseRouter extends BaseStatamicTool
{
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
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function executeInternal(array $arguments): array
    {
        return $this->executeAction($arguments);
    }
}
