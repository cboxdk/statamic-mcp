<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Development;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Illuminate\Support\Facades\Artisan;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Symfony\Component\Console\Output\BufferedOutput;

#[Title('Statamic Development Tool')]
class ConsoleDevelopmentTool extends BaseStatamicTool
{
    use \Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.development.console';
    }

    protected function getToolDescription(): string
    {
        return 'Execute Statamic development commands (make:fieldtype, make:tag, make:modifier, etc.) using internal API calls';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->raw('command', [
            'type' => 'string',
            'description' => 'Statamic command to execute',
            'enum' => [
                'make:action',
                'make:addon',
                'make:dictionary',
                'make:fieldtype',
                'make:filter',
                'make:modifier',
                'make:scope',
                'make:tag',
                'make:user',
                'make:widget',
                'install:collaboration',
                'install:eloquent-driver',
                'install:ssg',
                'assets:generate-presets',
                'assets:meta',
                'assets:clear-cache',
                'stache:clear',
                'stache:refresh',
                'stache:warm',
                'stache:doctor',
                'glide:clear',
                'static:clear',
                'static:warm',
                'search:insert',
                'search:update',
                'site:clear',
                'multisite',
                'support:details',
                'support:zip-blueprint',
            ],
        ])
            ->required()
            ->string('name')
            ->description('Name for the generated item (e.g., fieldtype name, tag name)')
            ->optional()
            ->string('addon')
            ->description('Addon name (for addon-specific generation)')
            ->optional()
            ->raw('options', [
                'type' => 'object',
                'description' => 'Additional command options as key-value pairs',
                'additionalProperties' => true,
            ])
            ->optional()
            ->boolean('php_only')
            ->description('Generate only PHP files (skip Vue components)')
            ->optional()
            ->boolean('force')
            ->description('Force overwrite existing files')
            ->optional()
            ->boolean('addon_mode')
            ->description('Generate in addon context')
            ->optional();
    }

    /**
     * Execute the tool logic.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $command = $arguments['command'];
        $name = $arguments['name'] ?? null;
        $addon = $arguments['addon'] ?? null;
        $options = $arguments['options'] ?? [];
        $phpOnly = $arguments['php_only'] ?? false;
        $force = $arguments['force'] ?? false;
        $addonMode = $arguments['addon_mode'] ?? false;

        // Build command arguments
        $commandArgs = [];

        if ($name) {
            $commandArgs['name'] = $name;
        }

        if ($addon) {
            $commandArgs['addon'] = $addon;
        }

        // Add standard options
        if ($phpOnly) {
            $commandArgs['--php'] = true;
        }

        if ($force) {
            $commandArgs['--force'] = true;
        }

        // Add custom options
        foreach ($options as $key => $value) {
            if (is_bool($value)) {
                $commandArgs["--{$key}"] = $value;
            } else {
                $commandArgs["--{$key}"] = $value;
            }
        }

        // Prepare output capture
        $output = new BufferedOutput;

        try {
            // Execute command using Artisan::call with prefixed command name
            $statamicCommand = "statamic:{$command}";
            $exitCode = Artisan::call($statamicCommand, $commandArgs, $output);

            $commandOutput = $output->fetch();

            // Analyze what was created
            $createdFilesResult = $this->analyzeCreatedFiles($commandOutput, $command, $name, $addon);

            if ($exitCode === 0) {
                return [
                    'command' => $statamicCommand,
                    'arguments' => $commandArgs,
                    'exit_code' => $exitCode,
                    'output' => $commandOutput,
                    'created_files' => $createdFilesResult,
                    'next_steps' => $this->getNextSteps($command, $name, $addon, $createdFilesResult),
                ];
            } else {
                return $this->createErrorResponse("Command failed with exit code {$exitCode}")->toArray();
            }

        } catch (\Exception $e) {
            return $this->createErrorResponse($e->getMessage())->toArray();
        }
    }

    /**
     * Analyze command output to detect created files.
     *
     * @return array<string, mixed>
     */
    private function analyzeCreatedFiles(string $output, string $command, ?string $name, ?string $addon): array
    {
        $createdFiles = [];

        // Parse output for file creation messages
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            // Look for file creation patterns
            if (preg_match('/\[(.*?)\] created successfully\.?/', $line, $matches)) {
                $createdFiles[] = [
                    'type' => 'file',
                    'path' => $matches[1],
                    'status' => 'created',
                ];
            }

            // Look for component creation messages
            if (preg_match('/Vue component \[(.*?)\] created/', $line, $matches)) {
                $createdFiles[] = [
                    'type' => 'vue_component',
                    'path' => $matches[1],
                    'status' => 'created',
                ];
            }
        }

        // Predict likely file locations based on command type
        if (empty($createdFiles) && $name) {
            $predictedFiles = $this->predictCreatedFiles($command, $name, $addon);
            $createdFiles = array_merge($createdFiles, $predictedFiles);
        }

        return ['files' => $createdFiles, 'count' => count($createdFiles)];
    }

    /**
     * Predict likely created files based on command and parameters.
     *
     * @return array<int, array<string, string>>
     */
    private function predictCreatedFiles(string $command, string $name, ?string $addon): array
    {
        $files = [];
        $basePath = $addon ? "addons/{$addon}" : 'app';

        switch ($command) {
            case 'make:fieldtype':
                $files[] = [
                    'type' => 'fieldtype_class',
                    'path' => "{$basePath}/Fieldtypes/{$name}.php",
                    'status' => 'likely_created',
                ];
                if (! $addon || ! $this->isPhpOnly()) {
                    $files[] = [
                        'type' => 'vue_component',
                        'path' => "{$basePath}/resources/js/components/fieldtypes/{$name}.vue",
                        'status' => 'likely_created',
                    ];
                }
                break;

            case 'make:tag':
                $files[] = [
                    'type' => 'tag_class',
                    'path' => "{$basePath}/Tags/{$name}.php",
                    'status' => 'likely_created',
                ];
                break;

            case 'make:modifier':
                $files[] = [
                    'type' => 'modifier_class',
                    'path' => "{$basePath}/Modifiers/{$name}.php",
                    'status' => 'likely_created',
                ];
                break;

            case 'make:filter':
                $files[] = [
                    'type' => 'filter_class',
                    'path' => "{$basePath}/Filters/{$name}.php",
                    'status' => 'likely_created',
                ];
                break;

            case 'addon:create':
                $files[] = [
                    'type' => 'addon_directory',
                    'path' => "addons/{$name}",
                    'status' => 'likely_created',
                ];
                $files[] = [
                    'type' => 'service_provider',
                    'path' => "addons/{$name}/src/ServiceProvider.php",
                    'status' => 'likely_created',
                ];
                break;
        }

        return $files;
    }

    /**
     * Get next steps and recommendations based on what was created.
     *
     * @param  array<string, mixed>  $createdFiles
     *
     * @return array<string, mixed>
     */
    private function getNextSteps(string $command, ?string $name, ?string $addon, array $createdFiles): array
    {
        $steps = [];

        switch ($command) {
            case 'make:fieldtype':
                if ($addon) {
                    $steps[] = 'Import and register the fieldtype component in resources/js/addon.js';  // Will be converted to associative
                    $steps[] = 'Build the addon assets with npm run build';  // Will be converted to associative
                } else {
                    $steps[] = 'Import and register the fieldtype component in resources/js/cp.js';  // Will be converted to associative
                    $steps[] = 'Build the control panel assets';  // Will be converted to associative
                }
                $steps[] = 'Test the fieldtype in a blueprint';  // Will be converted to associative
                break;

            case 'make:tag':
                $steps[] = 'Use the tag in your templates with {{ ' . strtolower($name ?? 'tag') . ' }}';
                $steps[] = 'Refer to Statamic tag documentation for parameter handling';  // Will be converted to associative
                break;

            case 'make:modifier':
                $steps[] = 'Use the modifier in templates with {{ value | ' . strtolower($name ?? 'modifier') . ' }}';
                $steps[] = 'Test with different data types';  // Will be converted to associative
                break;

            case 'make:filter':
                $steps[] = 'The filter is now available in queries and tag parameters';  // Will be converted to associative
                $steps[] = 'Test with collection or taxonomy queries';  // Will be converted to associative
                break;

            case 'addon:create':
                $steps[] = 'Add the addon to your composer.json require section';  // Will be converted to associative
                $steps[] = 'Run composer dump-autoload to register the addon';  // Will be converted to associative
                $steps[] = 'Start developing your addon features';  // Will be converted to associative
                break;

            default:
                $steps[] = 'Check the command output for specific next steps';  // Will be converted to associative
        }

        return ['steps' => $steps, 'total' => count($steps)];
    }

    /**
     * Check if PHP-only mode is active.
     */
    private function isPhpOnly(): bool
    {
        return request()->has('php_only') && request()->get('php_only');
    }
}
