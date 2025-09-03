<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\System;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;

#[Title('Verify Statamic License')]
class VerifyLicenseTool extends BaseStatamicTool
{
    protected function getToolName(): string
    {
        return 'statamic.system.license.verify';
    }

    protected function getToolDescription(): string
    {
        return 'Verify Statamic Pro license validity and activation status';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->boolean('check_remote')
            ->description('Check license validity against remote server')
            ->optional()
            ->boolean('include_diagnostics')
            ->description('Include diagnostic information')
            ->optional();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $checkRemote = $arguments['check_remote'] ?? false;
        $includeDiagnostics = $arguments['include_diagnostics'] ?? false;

        try {
            // Check if Statamic Pro is enabled using the official method
            $proEnabled = \Statamic\Statamic::pro();

            $verificationData = [
                'valid' => $proEnabled,
                'status' => $proEnabled ? 'valid' : 'free_mode',
                'pro_enabled' => $proEnabled,
                'local_check' => 'passed',
                'message' => $proEnabled ? 'Statamic Pro is active and valid' : 'Running in free mode',
            ];

            if ($checkRemote && $proEnabled) {
                // Note: Remote verification would require actual Statamic Pro license checking
                // This is a placeholder for the actual implementation
                $verificationData['remote_check'] = 'not_implemented';
                $verificationData['message'] .= ' (Remote verification not implemented)';
            }

            if ($includeDiagnostics) {
                $verificationData['diagnostics'] = [
                    'pro_enabled' => $proEnabled,
                    'config_path' => config_path(),
                    'environment' => app()->environment(),
                    'debug_mode' => config('app.debug', false),
                    'statamic_version' => \Statamic\Statamic::version(),
                ];
            }

            return [
                'verification' => $verificationData,
                'meta' => [
                    'statamic_version' => \Statamic\Statamic::version(),
                    'laravel_version' => app()->version(),
                    'timestamp' => now()->toISOString(),
                    'tool' => $this->getToolName(),
                ],
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('License verification failed: ' . $e->getMessage(), [
                'exception_type' => get_class($e),
            ])->toArray();
        }
    }
}
