<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\System;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;

#[Title('Statamic License Management Tool')]
class LicenseManagementTool extends BaseStatamicTool
{
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.system.license-management';
    }

    protected function getToolDescription(): string
    {
        return 'Manage Statamic Pro licenses and subscription status';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $this->addActionSchema($schema, ['status', 'verify']);
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        return ['license' => ['status' => 'unknown'], 'valid' => false];
    }
}
