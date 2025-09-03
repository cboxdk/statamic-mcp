<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\System;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;

#[Title('Get Statamic License Status')]
class GetLicenseStatusTool extends BaseStatamicTool
{
    protected function getToolName(): string
    {
        return 'statamic.system.license.status';
    }

    protected function getToolDescription(): string
    {
        return 'Get current Statamic Pro license status and subscription details';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->boolean('include_details')
            ->description('Include detailed license information')
            ->optional();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $includeDetails = $arguments['include_details'] ?? false;

        try {
            // Check if Statamic Pro is enabled using the official method
            $proStatus = \Statamic\Statamic::pro();

            $licenseData = [
                'status' => $proStatus ? 'active' : 'inactive',
                'type' => $proStatus ? 'pro' : 'free',
                'pro_enabled' => $proStatus,
                'message' => $proStatus ? 'Statamic Pro is active' : 'Statamic is running in free mode',
            ];

            if ($includeDetails && $proStatus) {
                // Add more detailed information if Pro is enabled
                $licenseData['features'] = [
                    'users' => 'unlimited',
                    'collections' => 'unlimited',
                    'sites' => 'unlimited',
                    'revisions' => 'enabled',
                ];
            }

            return [
                'license' => $licenseData,
                'valid' => $proStatus,
                'meta' => [
                    'statamic_version' => \Statamic\Statamic::version(),
                    'laravel_version' => app()->version(),
                    'timestamp' => now()->toISOString(),
                    'tool' => $this->getToolName(),
                ],
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not retrieve license status: ' . $e->getMessage(), [
                'exception_type' => get_class($e),
            ])->toArray();
        }
    }
}
