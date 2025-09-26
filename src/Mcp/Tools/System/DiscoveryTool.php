<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\System;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Illuminate\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Tool discovery and exploration system for agent education.
 *
 * Provides comprehensive tool discovery with:
 * - Intent-based tool recommendations
 * - Context-aware suggestions
 * - Capability exploration
 * - Usage pattern guidance
 */
#[IsReadOnly]
class DiscoveryTool extends BaseStatamicTool
{
    protected function getToolName(): string
    {
        return 'statamic.system.discover';
    }

    protected function getToolDescription(): string
    {
        return 'Discover and explore Statamic MCP tools with intent-based recommendations and context-aware guidance.';
    }

    protected function defineSchema(JsonSchema $schema): array
    {
        return [
            'intent' => JsonSchema::string()
                ->description('What you want to accomplish (e.g., "manage content", "analyze blueprints", "system health")')
                ->required(),
            'context' => JsonSchema::string()
                ->description('Current context or domain (e.g., "content creation", "development", "maintenance")')
                ->enum(['content', 'development', 'system', 'analysis', 'workflow', 'maintenance']),
            'expertise_level' => JsonSchema::string()
                ->description('Your familiarity with Statamic and MCP tools')
                ->enum(['beginner', 'intermediate', 'advanced', 'expert']),
            'exploration_type' => JsonSchema::string()
                ->description('Type of exploration desired')
                ->enum(['quick_overview', 'detailed_analysis', 'workflow_guidance', 'capability_mapping'])
                ->default('quick_overview'),
            'filter_by' => JsonSchema::array()
                ->description('Filter tools by capabilities (read_only, destructive, batch_operations, etc.)'),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function executeInternal(array $arguments): array
    {
        $intent = $arguments['intent'];
        $context = $arguments['context'] ?? 'general';
        $expertiseLevel = $arguments['expertise_level'] ?? 'intermediate';
        $explorationType = $arguments['exploration_type'] ?? 'quick_overview';

        // Analyze intent and provide recommendations
        $intentAnalysis = $this->analyzeIntent($intent, $context);
        $toolRecommendations = $this->getToolRecommendations($intentAnalysis, $expertiseLevel);
        $workflowGuidance = $this->getWorkflowGuidance($intentAnalysis, $explorationType);

        return [
            'success' => true,
            'discovery' => [
                'intent_analysis' => $intentAnalysis,
                'recommended_tools' => $toolRecommendations,
                'workflow_guidance' => $workflowGuidance,
                'capability_overview' => $this->getCapabilityOverview(),
                'learning_path' => $this->getLearningPath($expertiseLevel, $intent),
            ],
            'meta' => $this->createMetadata(),
        ];
    }

    /**
     * Analyze user intent to understand what they want to accomplish.
     *
     * @return array<string, mixed>
     */
    protected function analyzeIntent(string $intent, string $context): array
    {
        $intent = strtolower($intent);

        // Intent classification patterns
        $patterns = [
            'content_management' => [
                'keywords' => ['content', 'entries', 'create', 'manage', 'edit', 'publish'],
                'primary_domain' => 'content',
                'secondary_domains' => ['blueprints', 'collections'],
            ],
            'structure_setup' => [
                'keywords' => ['blueprint', 'collection', 'field', 'structure', 'setup', 'configure'],
                'primary_domain' => 'blueprints',
                'secondary_domains' => ['structures', 'collections'],
            ],
            'system_maintenance' => [
                'keywords' => ['cache', 'health', 'performance', 'system', 'maintenance', 'monitor'],
                'primary_domain' => 'system',
                'secondary_domains' => ['development'],
            ],
            'user_management' => [
                'keywords' => ['user', 'role', 'permission', 'access', 'auth', 'group'],
                'primary_domain' => 'users',
                'secondary_domains' => ['system'],
            ],
            'asset_management' => [
                'keywords' => ['asset', 'file', 'image', 'upload', 'media', 'storage'],
                'primary_domain' => 'assets',
                'secondary_domains' => ['content'],
            ],
            'analysis_exploration' => [
                'keywords' => ['analyze', 'explore', 'discover', 'understand', 'review', 'audit'],
                'primary_domain' => 'development',
                'secondary_domains' => ['blueprints', 'system'],
            ],
        ];

        $matchedPattern = null;
        $confidence = 0;

        foreach ($patterns as $pattern => $config) {
            $matches = 0;
            foreach ($config['keywords'] as $keyword) {
                if (str_contains($intent, $keyword)) {
                    $matches++;
                }
            }

            $patternConfidence = $matches / count($config['keywords']);
            if ($patternConfidence > $confidence) {
                $confidence = $patternConfidence;
                $matchedPattern = $pattern;
            }
        }

        return [
            'raw_intent' => $intent,
            'context' => $context,
            'classified_pattern' => $matchedPattern,
            'confidence' => $confidence,
            'domains' => $matchedPattern ? $patterns[$matchedPattern] : ['primary_domain' => 'general'],
            'complexity' => $this->assessComplexity($intent),
            'suggested_approach' => $this->getSuggestedApproach($matchedPattern, $context),
        ];
    }

    /**
     * Get tool recommendations based on intent analysis.
     *
     * @param  array<string, mixed>  $intentAnalysis
     *
     * @return array<string, mixed>
     */
    protected function getToolRecommendations(array $intentAnalysis, string $expertiseLevel): array
    {
        $primaryDomain = $intentAnalysis['domains']['primary_domain'] ?? 'general';
        $secondaryDomains = $intentAnalysis['domains']['secondary_domains'] ?? [];

        // Tool mapping based on domains
        $toolMap = [
            'content' => [
                'primary' => 'statamic.content',
                'description' => 'Manage entries, terms, and global values',
                'key_actions' => ['list', 'get', 'create', 'update', 'delete', 'publish'],
                'best_for' => 'Content creation and management workflows',
            ],
            'blueprints' => [
                'primary' => 'statamic.blueprints',
                'description' => 'Manage blueprint definitions and field schemas',
                'key_actions' => ['list', 'get', 'scan', 'types', 'validate'],
                'best_for' => 'Structure definition and field management',
            ],
            'structures' => [
                'primary' => 'statamic.structures',
                'description' => 'Manage collections, taxonomies, navigations, and sites',
                'key_actions' => ['list', 'get', 'create', 'update', 'delete'],
                'best_for' => 'Structural configuration and setup',
            ],
            'assets' => [
                'primary' => 'statamic.assets',
                'description' => 'Manage asset containers and files',
                'key_actions' => ['list', 'get', 'upload', 'move', 'copy', 'rename', 'delete'],
                'best_for' => 'File and media management',
            ],
            'users' => [
                'primary' => 'statamic.users',
                'description' => 'Manage users, roles, and groups',
                'key_actions' => ['list', 'get', 'create', 'update', 'delete', 'assign_role'],
                'best_for' => 'User and permission management',
            ],
            'system' => [
                'primary' => 'statamic.system',
                'description' => 'System operations, health checks, and cache management',
                'key_actions' => ['info', 'health', 'cache', 'clear_cache'],
                'best_for' => 'System maintenance and monitoring',
            ],
            'development' => [
                'primary' => 'statamic.development',
                'description' => 'Development tools and template analysis',
                'key_actions' => ['templates', 'antlers_validate', 'blade_lint'],
                'best_for' => 'Development workflow and code quality',
            ],
        ];

        $recommendations = [];

        // Primary recommendation
        if (isset($toolMap[$primaryDomain])) {
            $recommendations['primary'] = $toolMap[$primaryDomain];
        }

        // Secondary recommendations
        $recommendations['secondary'] = [];
        foreach ($secondaryDomains as $domain) {
            if (isset($toolMap[$domain])) {
                $recommendations['secondary'][] = $toolMap[$domain];
            }
        }

        // Add discovery and schema tools
        $recommendations['utility'] = [
            [
                'primary' => 'statamic.system.discover',
                'description' => 'Tool discovery and exploration (current tool)',
                'best_for' => 'Understanding available capabilities',
            ],
            [
                'primary' => 'statamic.system.schema',
                'description' => 'Tool schema inspection and documentation',
                'best_for' => 'Understanding tool parameters and usage',
            ],
        ];

        // Expertise-based filtering
        if ($expertiseLevel === 'beginner') {
            $recommendations['beginner_tips'] = [
                'Start with list and get actions to explore existing content',
                'Use dry_run=true for any destructive operations',
                'Use help action on any tool for detailed guidance',
                'Begin with read-only operations before making changes',
            ];
        }

        return $recommendations;
    }

    /**
     * Get workflow guidance based on intent and exploration type.
     *
     * @param  array<string, mixed>  $intentAnalysis
     *
     * @return array<string, mixed>
     */
    protected function getWorkflowGuidance(array $intentAnalysis, string $explorationType): array
    {
        $pattern = $intentAnalysis['classified_pattern'];
        $complexity = $intentAnalysis['complexity'];

        $workflows = [
            'content_management' => [
                'setup_phase' => [
                    'step1' => 'Use statamic.blueprints to explore available field schemas',
                    'step2' => 'Use statamic.structures to understand collections and taxonomies',
                    'step3' => 'Use statamic.content to explore existing content patterns',
                ],
                'execution_phase' => [
                    'step1' => 'Create or modify content with statamic.content',
                    'step2' => 'Validate content against blueprints',
                    'step3' => 'Publish and manage content lifecycle',
                ],
                'maintenance_phase' => [
                    'step1' => 'Monitor content health and relationships',
                    'step2' => 'Perform bulk operations as needed',
                    'step3' => 'Archive or clean up obsolete content',
                ],
            ],
            'structure_setup' => [
                'planning_phase' => [
                    'step1' => 'Analyze existing blueprints with statamic.blueprints list/scan',
                    'step2' => 'Review field types and relationships',
                    'step3' => 'Plan new field structures and dependencies',
                ],
                'implementation_phase' => [
                    'step1' => 'Create collections with statamic.structures',
                    'step2' => 'Define blueprints with statamic.blueprints',
                    'step3' => 'Test with sample content using statamic.content',
                ],
                'validation_phase' => [
                    'step1' => 'Validate blueprint schemas',
                    'step2' => 'Test content creation workflows',
                    'step3' => 'Document structure decisions',
                ],
            ],
            'system_maintenance' => [
                'assessment_phase' => [
                    'step1' => 'Check system health with statamic.system info/health',
                    'step2' => 'Analyze cache status and performance',
                    'step3' => 'Review system configuration and dependencies',
                ],
                'maintenance_phase' => [
                    'step1' => 'Clear appropriate caches with statamic.system',
                    'step2' => 'Monitor system resources and performance',
                    'step3' => 'Perform routine maintenance tasks',
                ],
                'optimization_phase' => [
                    'step1' => 'Identify performance bottlenecks',
                    'step2' => 'Optimize cache strategies',
                    'step3' => 'Document maintenance procedures',
                ],
            ],
        ];

        $guidance = $workflows[$pattern] ?? [
            'general_approach' => [
                'step1' => 'Use discovery tools to understand available capabilities',
                'step2' => 'Start with read-only operations to explore current state',
                'step3' => 'Plan changes using dry_run operations',
                'step4' => 'Execute changes with proper safety protocols',
            ],
        ];

        // Add complexity-specific guidance
        if ($complexity === 'high') {
            $guidance['complexity_notes'] = [
                'recommendation' => 'Break down into smaller, manageable steps',
                'safety' => 'Use extensive dry_run testing before execution',
                'backup' => 'Consider creating backups before major changes',
                'monitoring' => 'Monitor each step for unexpected side effects',
            ];
        }

        return [
            'workflow_pattern' => $pattern,
            'complexity_level' => $complexity,
            'recommended_workflow' => $guidance,
            'safety_considerations' => $this->getSafetyConsiderations($pattern),
            'common_pitfalls' => $this->getCommonPitfalls($pattern),
        ];
    }

    /**
     * Get capability overview of all available tools.
     *
     * @return array<string, mixed>
     */
    protected function getCapabilityOverview(): array
    {
        return [
            'tool_categories' => [
                'content_management' => [
                    'tools' => ['statamic.content'],
                    'capabilities' => ['CRUD operations', 'Publishing workflow', 'Relationship management'],
                    'read_only' => false,
                ],
                'structure_management' => [
                    'tools' => ['statamic.blueprints', 'statamic.structures'],
                    'capabilities' => ['Schema definition', 'Collection management', 'Field type analysis'],
                    'read_only' => false,
                ],
                'asset_management' => [
                    'tools' => ['statamic.assets'],
                    'capabilities' => ['File operations', 'Container management', 'Upload handling'],
                    'read_only' => false,
                ],
                'user_management' => [
                    'tools' => ['statamic.users'],
                    'capabilities' => ['User CRUD', 'Role management', 'Permission assignment'],
                    'read_only' => false,
                ],
                'system_operations' => [
                    'tools' => ['statamic.system'],
                    'capabilities' => ['Health monitoring', 'Cache management', 'System information'],
                    'read_only' => true,
                ],
                'development_tools' => [
                    'tools' => ['statamic.development'],
                    'capabilities' => ['Template analysis', 'Code validation', 'Type generation'],
                    'read_only' => true,
                ],
                'discovery_tools' => [
                    'tools' => ['statamic.system.discover', 'statamic.system.schema'],
                    'capabilities' => ['Tool exploration', 'Schema inspection', 'Usage guidance'],
                    'read_only' => true,
                ],
            ],
            'cross_cutting_features' => [
                'safety_protocols' => ['dry_run simulation', 'confirmation requirements', 'error handling'],
                'help_systems' => ['Contextual guidance', 'Usage examples', 'Best practices'],
                'discovery_mechanisms' => ['Intent analysis', 'Capability mapping', 'Workflow guidance'],
            ],
        ];
    }

    /**
     * Get learning path based on expertise level and intent.
     *
     * @return array<string, mixed>
     */
    protected function getLearningPath(string $expertiseLevel, string $intent): array
    {
        $basePath = [
            'beginner' => [
                'phase1' => 'Explore existing content and structures (read-only operations)',
                'phase2' => 'Understand schemas and relationships',
                'phase3' => 'Practice safe operations with dry_run',
                'phase4' => 'Execute simple CRUD operations',
                'phase5' => 'Build complex workflows',
            ],
            'intermediate' => [
                'phase1' => 'Review current system configuration',
                'phase2' => 'Plan changes using analysis tools',
                'phase3' => 'Execute changes with safety protocols',
                'phase4' => 'Optimize and refine workflows',
            ],
            'advanced' => [
                'phase1' => 'Analyze system architecture and dependencies',
                'phase2' => 'Design complex workflow automations',
                'phase3' => 'Implement with comprehensive testing',
                'phase4' => 'Document and share best practices',
            ],
        ];

        return [
            'expertise_level' => $expertiseLevel,
            'learning_phases' => $basePath[$expertiseLevel] ?? $basePath['intermediate'],
            'recommended_tools_progression' => $this->getToolProgression($expertiseLevel),
            'skill_development_focus' => $this->getSkillFocus($expertiseLevel, $intent),
        ];
    }

    /**
     * Assess complexity of the user's intent.
     */
    protected function assessComplexity(string $intent): string
    {
        $complexityIndicators = [
            'high' => ['migrate', 'transform', 'bulk', 'batch', 'automate', 'integrate'],
            'medium' => ['setup', 'configure', 'manage', 'organize', 'update'],
            'low' => ['view', 'list', 'get', 'show', 'display', 'read'],
        ];

        foreach ($complexityIndicators as $level => $indicators) {
            foreach ($indicators as $indicator) {
                if (str_contains(strtolower($intent), $indicator)) {
                    return $level;
                }
            }
        }

        return 'medium';
    }

    /**
     * Get suggested approach based on pattern and context.
     */
    protected function getSuggestedApproach(?string $pattern, string $context): string
    {
        if (! $pattern) {
            return 'exploratory';
        }

        $approaches = [
            'content_management' => 'workflow-driven',
            'structure_setup' => 'planning-first',
            'system_maintenance' => 'assessment-based',
            'user_management' => 'security-focused',
            'asset_management' => 'organization-focused',
            'analysis_exploration' => 'discovery-driven',
        ];

        return $approaches[$pattern] ?? 'systematic';
    }

    /**
     * Get safety considerations for a pattern.
     *
     * @return array<string>
     */
    protected function getSafetyConsiderations(?string $pattern): array
    {
        $safety = [
            'content_management' => [
                'Always backup before bulk operations',
                'Validate blueprint compliance before publishing',
                'Consider content relationships before deletion',
            ],
            'structure_setup' => [
                'Test blueprint changes with sample content',
                'Understand field dependencies before modification',
                'Plan migration paths for existing content',
            ],
            'system_maintenance' => [
                'Check system health before major operations',
                'Monitor cache clearing impact on performance',
                'Verify backup systems before maintenance',
            ],
        ];

        return $safety[$pattern] ?? [
            'Use dry_run for all destructive operations',
            'Test in development environment first',
            'Monitor for unexpected side effects',
        ];
    }

    /**
     * Get common pitfalls for a pattern.
     *
     * @return array<string>
     */
    protected function getCommonPitfalls(?string $pattern): array
    {
        $pitfalls = [
            'content_management' => [
                'Forgetting to check blueprint field requirements',
                'Not considering content relationships in deletion',
                'Bulk operations without proper validation',
            ],
            'structure_setup' => [
                'Creating circular dependencies in blueprints',
                'Not planning for content migration',
                'Insufficient field validation rules',
            ],
            'system_maintenance' => [
                'Clearing caches during high traffic periods',
                'Not monitoring system resources during operations',
                'Performing maintenance without proper backups',
            ],
        ];

        return $pitfalls[$pattern] ?? [
            'Insufficient testing before execution',
            'Not understanding tool capabilities fully',
            'Skipping safety protocols',
        ];
    }

    /**
     * Get tool progression for different expertise levels.
     *
     * @return array<string>
     */
    protected function getToolProgression(string $expertiseLevel): array
    {
        $progressions = [
            'beginner' => [
                'statamic.system.discover',
                'statamic.blueprints (list, get)',
                'statamic.content (list, get)',
                'statamic.structures (list, get)',
                'statamic.content (create, update)',
                'statamic.system (info, health)',
            ],
            'intermediate' => [
                'statamic.system.discover',
                'statamic.blueprints (all actions)',
                'statamic.content (all actions)',
                'statamic.structures (all actions)',
                'statamic.development (templates, validation)',
                'statamic.system (cache management)',
            ],
            'advanced' => [
                'All tools with full capabilities',
                'Complex workflow combinations',
                'Custom automation patterns',
                'Performance optimization techniques',
            ],
        ];

        return $progressions[$expertiseLevel] ?? $progressions['intermediate'];
    }

    /**
     * Get skill development focus.
     *
     * @return array<string, array<string>>
     */
    protected function getSkillFocus(string $expertiseLevel, string $intent): array
    {
        return [
            'technical_skills' => [
                'Understanding Statamic data models',
                'Mastering safety protocols',
                'Developing workflow automation',
            ],
            'best_practices' => [
                'Systematic approach to changes',
                'Comprehensive testing methodologies',
                'Documentation and knowledge sharing',
            ],
            'advanced_techniques' => [
                'Complex relationship management',
                'Performance optimization strategies',
                'Custom development patterns',
            ],
        ];
    }

    /**
     * Create metadata for tool responses.
     *
     * @return array<string, mixed>
     */
    protected function createMetadata(): array
    {
        return [
            'tool' => $this->getToolName(),
            'timestamp' => now()->toISOString(),
            'version' => '1.0.0',
        ];
    }
}
