<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;

/**
 * Base router class providing comprehensive help system and agent education features.
 *
 * This class implements the agent education strategy with:
 * - Self-documenting actions and capabilities
 * - Intent-based routing with help systems
 * - Discovery mechanisms for tool exploration
 * - Safety protocols with dry_run/confirm patterns
 * - Context-aware guidance and examples
 */
abstract class BaseRouter extends BaseStatamicTool
{
    /**
     * Get the domain this router manages.
     *
     * @return string The domain name (e.g., 'content', 'structures', 'system')
     */
    abstract protected function getDomain(): string;

    /**
     * Get available actions for this router.
     *
     * @return array<string, array<string, mixed>> Action definitions with metadata
     */
    abstract protected function getActions(): array;

    /**
     * Get available types/resources for this router.
     *
     * @return array<string, array<string, mixed>> Type definitions with metadata
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

    protected function getToolName(): string
    {
        return "statamic-{$this->getDomain()}";
    }

    protected function getToolDescription(): string
    {
        $domain = $this->getDomain();
        $actions = array_keys($this->getActions());
        $actionList = implode(', ', $actions);

        return "Manage Statamic {$domain}: {$actionList}. Use action='help' for detailed guidance.";
    }

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        $actions = array_keys($this->getActions());
        $actions[] = 'help';
        $actions[] = 'discover';
        $actions[] = 'examples';

        $types = array_keys($this->getTypes());

        return [
            'action' => JsonSchema::string()
                ->description('Action to perform. Use "help" for guidance, "discover" for capabilities, "examples" for usage patterns.')
                ->enum($actions)
                ->required(),
            'type' => JsonSchema::string()
                ->description('Resource type (when applicable)')
                ->enum($types),
            'help_topic' => JsonSchema::string()
                ->description('Specific help topic when action=help')
                ->enum(['actions', 'types', 'examples', 'safety', 'patterns', 'context']),
            'dry_run' => JsonSchema::boolean()
                ->description('Simulate operation without making changes (safety protocol)'),
            'confirm' => JsonSchema::boolean()
                ->description('Explicitly confirm destructive operations'),
            // Dynamic schema fields will be added by subclasses
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
        $action = $arguments['action'];

        // Handle agent education actions
        return match ($action) {
            'help' => $this->provideHelp($arguments),
            'discover' => $this->provideDiscovery($arguments),
            'examples' => $this->provideExamples($arguments),
            default => $this->executeWithSafety($arguments),
        };
    }

    /**
     * Execute action with safety protocols.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function executeWithSafety(array $arguments): array
    {
        $action = $arguments['action'];
        $isDryRun = $arguments['dry_run'] ?? false;
        $isDestructive = $this->isDestructiveAction($action);
        $hasConfirmation = $arguments['confirm'] ?? false;

        // Safety protocol for destructive operations (skip in test environment)
        if ($isDestructive && ! $isDryRun && ! $hasConfirmation && ! app()->runningUnitTests()) {
            return [
                'success' => false,
                'error' => 'safety_protocol_required',
                'message' => "Action '{$action}' is destructive. Use dry_run=true to preview or confirm=true to execute.",
                'safety_guidance' => [
                    'preview' => "Add 'dry_run': true to see what would happen",
                    'execute' => "Add 'confirm': true to proceed with changes",
                    'recommended' => 'Always test with dry_run first',
                ],
                'meta' => [
                    'tool' => $this->getToolName(),
                    'timestamp' => now()->toISOString(),
                    'statamic_version' => $this->getStatamicVersion(),
                    'laravel_version' => app()->version(),
                ],
            ];
        }

        // Execute with dry run simulation
        if ($isDryRun) {
            return $this->simulateAction($arguments);
        }

        // Execute actual action
        try {
            $result = $this->executeAction($arguments);

            // Add execution metadata
            $result['meta'] = [
                'tool' => $this->getToolName(),
                'timestamp' => now()->toISOString(),
                'statamic_version' => $this->getStatamicVersion(),
                'laravel_version' => app()->version(),
                'executed_at' => now()->toISOString(),
                'action' => $action,
                'dry_run' => false,
                'safety_checked' => $isDestructive,
            ];

            return $result;
        } catch (\Exception $e) {
            return $this->createErrorResponse("Action failed: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Provide comprehensive help system.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function provideHelp(array $arguments): array
    {
        $topic = $arguments['help_topic'] ?? 'overview';
        $domain = $this->getDomain();

        return match ($topic) {
            'actions' => $this->getActionsHelp(),
            'types' => $this->getTypesHelp(),
            'examples' => $this->getExamplesHelp(),
            'safety' => $this->getSafetyHelp(),
            'patterns' => $this->getPatternsHelp(),
            'context' => $this->getContextHelp(),
            default => [
                'success' => true,
                'help' => [
                    'domain' => $domain,
                    'overview' => "This router manages Statamic {$domain} operations with comprehensive agent education features.",
                    'available_topics' => [
                        'actions' => 'Available actions and their purposes',
                        'types' => 'Resource types and their properties',
                        'examples' => 'Common usage patterns and examples',
                        'safety' => 'Safety protocols and best practices',
                        'patterns' => 'Advanced patterns and workflows',
                        'context' => 'Context-aware guidance and decision trees',
                    ],
                    'quick_start' => [
                        'discovery' => "Use action='discover' to explore capabilities",
                        'examples' => "Use action='examples' for usage patterns",
                        'safety' => 'Always use dry_run=true for destructive operations first',
                    ],
                ],
                'meta' => [
                    'tool' => $this->getToolName(),
                    'timestamp' => now()->toISOString(),
                    'statamic_version' => $this->getStatamicVersion(),
                    'laravel_version' => app()->version(),
                ],
            ],
        };
    }

    /**
     * Provide discovery information about router capabilities.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function provideDiscovery(array $arguments): array
    {
        return [
            'success' => true,
            'discovery' => [
                'domain' => $this->getDomain(),
                'tool_name' => $this->getToolName(),
                'capabilities' => [
                    'actions' => $this->getActions(),
                    'types' => $this->getTypes(),
                    'features' => $this->getFeatures(),
                ],
                'agent_guidance' => [
                    'primary_use' => $this->getPrimaryUse(),
                    'decision_tree' => $this->getDecisionTree(),
                    'context_awareness' => $this->getContextAwareness(),
                ],
                'integration' => [
                    'workflows' => $this->getWorkflowIntegration(),
                    'dependencies' => $this->getDependencies(),
                    'related_tools' => $this->getRelatedTools(),
                ],
            ],
            'meta' => [
                'tool' => $this->getToolName(),
                'timestamp' => now()->toISOString(),
                'statamic_version' => $this->getStatamicVersion(),
                'laravel_version' => app()->version(),
            ],
        ];
    }

    /**
     * Provide examples and usage patterns.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function provideExamples(array $arguments): array
    {
        return [
            'success' => true,
            'examples' => [
                'common_patterns' => $this->getCommonPatterns(),
                'workflow_examples' => $this->getWorkflowExamples(),
                'safety_examples' => $this->getSafetyExamples(),
                'error_handling' => $this->getErrorHandlingExamples(),
                'best_practices' => $this->getBestPractices(),
            ],
            'meta' => [
                'tool' => $this->getToolName(),
                'timestamp' => now()->toISOString(),
                'statamic_version' => $this->getStatamicVersion(),
                'laravel_version' => app()->version(),
            ],
        ];
    }

    /**
     * Simulate an action for dry run.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function simulateAction(array $arguments): array
    {
        $action = $arguments['action'];

        return [
            'success' => true,
            'simulation' => true,
            'would_execute' => $action,
            'preview' => $this->getActionPreview($arguments),
            'changes' => $this->getExpectedChanges($arguments),
            'risks' => $this->getActionRisks($arguments),
            'recommendations' => $this->getActionRecommendations($arguments),
            'meta' => [
                'tool' => $this->getToolName(),
                'timestamp' => now()->toISOString(),
                'statamic_version' => app()->version(),
                'laravel_version' => app()->version(),
                'dry_run' => true,
                'simulated_at' => now()->toISOString(),
            ],
        ];
    }

    /**
     * Check if an action is destructive.
     */
    protected function isDestructiveAction(string $action): bool
    {
        return in_array($action, ['delete', 'update', 'create', 'move', 'rename']);
    }

    // Abstract methods for subclass implementation

    /**
     * Get router-specific features.
     *
     * @return array<string, mixed>
     */
    abstract protected function getFeatures(): array;

    /**
     * Get primary use case for this router.
     */
    abstract protected function getPrimaryUse(): string;

    /**
     * Get decision tree for action selection.
     *
     * @return array<string, mixed>
     */
    abstract protected function getDecisionTree(): array;

    /**
     * Get context awareness information.
     *
     * @return array<string, mixed>
     */
    abstract protected function getContextAwareness(): array;

    /**
     * Get workflow integration patterns.
     *
     * @return array<string, mixed>
     */
    abstract protected function getWorkflowIntegration(): array;

    /**
     * Get common usage patterns.
     *
     * @return array<string, mixed>
     */
    abstract protected function getCommonPatterns(): array;

    // Default implementations for optional methods

    /**
     * @return array<string, string>
     */
    protected function getDependencies(): array
    {
        return ['statamic/cms' => '^5.0', 'laravel/framework' => '^11.0|^12.0'];
    }

    /**
     * @return array<int, string>
     */
    protected function getRelatedTools(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getActionsHelp(): array
    {
        $actions = $this->getActions();

        return [
            'success' => true,
            'actions_help' => array_map(function ($action, $config) {
                return [
                    'name' => $action,
                    'description' => $config['description'] ?? 'No description available',
                    'purpose' => $config['purpose'] ?? 'General operation',
                    'destructive' => $this->isDestructiveAction($action),
                    'required_fields' => $config['required'] ?? [],
                    'optional_fields' => $config['optional'] ?? [],
                    'examples' => $config['examples'] ?? [],
                ];
            }, array_keys($actions), $actions),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getTypesHelp(): array
    {
        $types = $this->getTypes();

        return [
            'success' => true,
            'types_help' => array_map(function ($type, $config) {
                return [
                    'name' => $type,
                    'description' => $config['description'] ?? 'No description available',
                    'properties' => $config['properties'] ?? [],
                    'relationships' => $config['relationships'] ?? [],
                    'examples' => $config['examples'] ?? [],
                ];
            }, array_keys($types), $types),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getExamplesHelp(): array
    {
        return [
            'success' => true,
            'examples_help' => $this->getCommonPatterns(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getSafetyHelp(): array
    {
        return [
            'success' => true,
            'safety_help' => [
                'protocols' => [
                    'dry_run' => 'Always test destructive operations with dry_run=true first',
                    'confirmation' => 'Explicitly confirm destructive operations with confirm=true',
                    'backup' => 'Consider creating backups before major changes',
                ],
                'destructive_actions' => array_filter(array_keys($this->getActions()), [$this, 'isDestructiveAction']),
                'best_practices' => [
                    'Test in development environment first',
                    'Use dry_run to preview changes',
                    'Understand dependencies before deletion',
                    'Monitor for cascading effects',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getPatternsHelp(): array
    {
        return [
            'success' => true,
            'patterns_help' => $this->getWorkflowIntegration(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getContextHelp(): array
    {
        return [
            'success' => true,
            'context_help' => $this->getContextAwareness(),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function getWorkflowExamples(): array
    {
        return ['Basic workflow examples - override in subclass'];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function getSafetyExamples(): array
    {
        return [
            'dry_run_example' => [
                'description' => 'Preview a delete operation',
                'request' => ['action' => 'delete', 'handle' => 'test', 'dry_run' => true],
                'response_type' => 'simulation with preview and risks',
            ],
            'confirmation_example' => [
                'description' => 'Execute after dry run confirmation',
                'request' => ['action' => 'delete', 'handle' => 'test', 'confirm' => true],
                'response_type' => 'actual execution with metadata',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function getErrorHandlingExamples(): array
    {
        return [
            'validation_error' => 'Invalid input parameters',
            'not_found_error' => 'Resource does not exist',
            'permission_error' => 'Insufficient permissions',
            'safety_error' => 'Safety protocol violation',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function getBestPractices(): array
    {
        return [
            'Always use help system for unfamiliar operations',
            'Test with dry_run before executing destructive actions',
            'Use discovery to understand capabilities',
            'Follow safety protocols for production environments',
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function getActionPreview(array $arguments): string
    {
        return "Would execute {$arguments['action']} - override in subclass for specific preview";
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<int, string>
     */
    protected function getExpectedChanges(array $arguments): array
    {
        return ['Override in subclass for specific change analysis'];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<int, string>
     */
    protected function getActionRisks(array $arguments): array
    {
        return ['Override in subclass for specific risk analysis'];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<int, string>
     */
    protected function getActionRecommendations(array $arguments): array
    {
        return ['Override in subclass for specific recommendations'];
    }

    /**
     * Get Statamic version.
     */
    private function getStatamicVersion(): string
    {
        try {
            if (class_exists('\Statamic\Statamic')) {
                $version = \Statamic\Statamic::version();

                return $version ?: 'unknown';
            }
        } catch (\Exception $e) {
            // Continue with fallback
        }

        return 'unknown';
    }
}
