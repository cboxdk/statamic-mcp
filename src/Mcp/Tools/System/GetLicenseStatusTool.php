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
            // Check if Statamic Pro is available
            $isProAvailable = class_exists(\Statamic\Pro\Pro::class);

            if (! $isProAvailable) {
                return [
                    'license' => [
                        'status' => 'not_installed',
                        'type' => 'free',
                        'message' => 'Statamic Pro is not installed',
                    ],
                    'valid' => false,
                    'meta' => [
                        'statamic_version' => app('statamic.version'),
                        'laravel_version' => app()->version(),
                        'timestamp' => now()->toISOString(),
                        'tool' => $this->getToolName(),
                    ],
                ];
            }

            // Get Pro status if available
            $proStatus = false;
            if ($isProAvailable) { // @phpstan-ignore if.alwaysTrue
                try {
                    $proClass = \Statamic\Pro\Pro::class; // @phpstan-ignore class.notFound
                    if (method_exists($proClass, 'enabled')) { // @phpstan-ignore function.impossibleType
                        $proStatus = $proClass::enabled(); // @phpstan-ignore class.notFound
                    }
                } catch (\Throwable $e) {
                    // Ignore errors when Pro class is not available
                }
            }

            $licenseData = [
                'status' => $proStatus ? 'active' : 'inactive',
                'type' => $proStatus ? 'pro' : 'free',
                'pro_available' => $isProAvailable,
                'pro_enabled' => $proStatus,
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
                    'statamic_version' => app('statamic.version'),
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
