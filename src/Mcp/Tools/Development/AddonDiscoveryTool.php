<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Development;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;

#[Title('Statamic Addon Discovery Tool')]
class AddonDiscoveryTool extends BaseStatamicTool
{
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.development.addon-discovery';
    }

    protected function getToolDescription(): string
    {
        return 'Discover and analyze Statamic addons and their capabilities';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $this->addActionSchema($schema, ['list', 'analyze']);
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        return ['addons' => [], 'count' => 0];
    }
}
