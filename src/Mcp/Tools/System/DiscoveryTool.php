<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\System;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Illuminate\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use ReflectionClass;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\User;

/**
 * Enhanced tool discovery and exploration system for agent education.
 *
 * Provides comprehensive tool discovery with:
 * - Dynamic router action discovery through reflection
 * - Enhanced intent classification with improved confidence scoring
 * - Real-time system state integration
 * - Concrete workflow templates with actual examples
 * - Comprehensive tool information with parameter mapping
 * - Context-aware recommendations based on current project state
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

        // Enhanced analysis with dynamic discovery
        $intentAnalysis = $this->enhancedIntentAnalysis($intent, $context);
        $systemState = $this->getCurrentSystemState();
        $toolInformation = $this->getDynamicToolInformation();
        $toolRecommendations = $this->getEnhancedToolRecommendations($intentAnalysis, $expertiseLevel, $systemState);
        $workflowGuidance = $this->getConcreteWorkflowGuidance($intentAnalysis, $explorationType, $systemState);

        return [
            'success' => true,
            'discovery' => [
                'intent_analysis' => $intentAnalysis,
                'system_state' => $systemState,
                'tool_information' => $toolInformation,
                'recommended_tools' => $toolRecommendations,
                'workflow_guidance' => $workflowGuidance,
                'capability_overview' => $this->getEnhancedCapabilityOverview(),
                'learning_path' => $this->getContextualLearningPath($expertiseLevel, $intent, $systemState),
                'debug_enhanced_active' => true,
            ],
            'meta' => $this->createEnhancedMetadata(),
        ];
    }

    /**
     * Enhanced intent analysis with improved confidence scoring and context awareness.
     *
     * @return array<string, mixed>
     */
    protected function enhancedIntentAnalysis(string $intent, string $context): array
    {
        $intent = strtolower($intent);

        // Enhanced pattern definitions with weighted keywords and context factors
        $patterns = [
            'content_management' => [
                'keywords' => [
                    'high_weight' => ['content', 'entries', 'publish', 'article', 'page'],
                    'medium_weight' => ['create', 'manage', 'edit', 'update', 'write'],
                    'low_weight' => ['cms', 'site', 'web'],
                ],
                'context_modifiers' => [
                    'content' => 1.5,
                    'workflow' => 1.3,
                    'general' => 1.0,
                ],
                'primary_domain' => 'content',
                'secondary_domains' => ['blueprints', 'structures'],
                'complexity_indicators' => ['bulk', 'batch', 'multiple', 'many'],
            ],
            'structure_setup' => [
                'keywords' => [
                    'high_weight' => ['blueprint', 'field', 'schema', 'structure'],
                    'medium_weight' => ['collection', 'taxonomy', 'setup', 'configure'],
                    'low_weight' => ['design', 'plan', 'organize'],
                ],
                'context_modifiers' => [
                    'development' => 1.5,
                    'system' => 1.2,
                    'general' => 1.0,
                ],
                'primary_domain' => 'blueprints',
                'secondary_domains' => ['structures', 'content'],
                'complexity_indicators' => ['complex', 'advanced', 'custom', 'relationship'],
            ],
            'system_maintenance' => [
                'keywords' => [
                    'high_weight' => ['cache', 'health', 'performance', 'monitor'],
                    'medium_weight' => ['system', 'maintenance', 'optimize', 'debug'],
                    'low_weight' => ['check', 'status', 'info'],
                ],
                'context_modifiers' => [
                    'system' => 1.5,
                    'maintenance' => 1.4,
                    'general' => 1.0,
                ],
                'primary_domain' => 'system',
                'secondary_domains' => ['development'],
                'complexity_indicators' => ['critical', 'emergency', 'production', 'urgent'],
            ],
            'user_management' => [
                'keywords' => [
                    'high_weight' => ['user', 'role', 'permission', 'access'],
                    'medium_weight' => ['auth', 'security', 'group', 'manage'],
                    'low_weight' => ['login', 'account', 'profile'],
                ],
                'context_modifiers' => [
                    'system' => 1.3,
                    'maintenance' => 1.2,
                    'general' => 1.0,
                ],
                'primary_domain' => 'users',
                'secondary_domains' => ['system'],
                'complexity_indicators' => ['multiple', 'bulk', 'migrate', 'import'],
            ],
            'asset_management' => [
                'keywords' => [
                    'high_weight' => ['asset', 'file', 'image', 'media'],
                    'medium_weight' => ['upload', 'storage', 'organize', 'library'],
                    'low_weight' => ['picture', 'photo', 'document'],
                ],
                'context_modifiers' => [
                    'content' => 1.3,
                    'workflow' => 1.2,
                    'general' => 1.0,
                ],
                'primary_domain' => 'assets',
                'secondary_domains' => ['content'],
                'complexity_indicators' => ['large', 'bulk', 'migration', 'conversion'],
            ],
            'analysis_exploration' => [
                'keywords' => [
                    'high_weight' => ['analyze', 'explore', 'discover', 'audit'],
                    'medium_weight' => ['understand', 'review', 'inspect', 'examine'],
                    'low_weight' => ['show', 'list', 'view'],
                ],
                'context_modifiers' => [
                    'analysis' => 1.5,
                    'development' => 1.3,
                    'general' => 1.0,
                ],
                'primary_domain' => 'development',
                'secondary_domains' => ['blueprints', 'system'],
                'complexity_indicators' => ['deep', 'comprehensive', 'detailed', 'thorough'],
            ],
        ];

        $scores = [];
        $complexityFactors = [];

        // Calculate weighted scores for each pattern
        foreach ($patterns as $pattern => $config) {
            $score = 0;
            $complexityScore = 0;

            // Weight-based keyword matching
            foreach ($config['keywords'] as $weight => $keywords) {
                $weightMultiplier = match ($weight) {
                    'high_weight' => 3.0,
                    'medium_weight' => 2.0,
                    'low_weight' => 1.0,
                };

                foreach ($keywords as $keyword) {
                    if (str_contains($intent, $keyword)) {
                        $score += $weightMultiplier;
                    }
                }
            }

            // Context modifier
            $contextModifier = $config['context_modifiers'][$context] ?? 1.0;
            $score *= $contextModifier;

            // Complexity assessment
            foreach ($config['complexity_indicators'] as $indicator) {
                if (str_contains($intent, $indicator)) {
                    $complexityScore += 1;
                }
            }

            $scores[$pattern] = $score;
            $complexityFactors[$pattern] = $complexityScore;
        }

        // Find best match
        $bestPattern = array_key_first(array_filter($scores, fn ($score) => $score === max($scores))) ?? 'general';
        $confidence = $scores[$bestPattern] ?? 0;

        // Normalize confidence to 0-1 scale
        $maxPossibleScore = 15; // Theoretical maximum based on weights
        $normalizedConfidence = min($confidence / $maxPossibleScore, 1.0);

        // Determine overall complexity
        $overallComplexity = $this->calculateComplexity($intent, $complexityFactors[$bestPattern] ?? 0);

        return [
            'raw_intent' => $intent,
            'context' => $context,
            'classified_pattern' => $bestPattern,
            'confidence' => $normalizedConfidence,
            'confidence_level' => $this->getConfidenceLevel($normalizedConfidence),
            'all_scores' => $scores,
            'domains' => $patterns[$bestPattern] ?? ['primary_domain' => 'general'],
            'complexity' => $overallComplexity,
            'complexity_factors' => $complexityFactors[$bestPattern] ?? 0,
            'suggested_approach' => $this->getSuggestedApproach($bestPattern, $context),
            'intent_suggestions' => $this->generateIntentSuggestions($intent, $scores),
        ];
    }

    /**
     * Get current system state for context-aware recommendations.
     *
     * @return array<string, mixed>
     */
    protected function getCurrentSystemState(): array
    {
        $state = [
            'timestamp' => now()->toISOString(),
            'collections' => [],
            'taxonomies' => [],
            'users_count' => 0,
            'roles' => [],
            'asset_containers' => [],
            'global_sets' => [],
            'sites' => [],
        ];

        try {
            // Collections
            $collections = Collection::all();
            foreach ($collections as $collection) {
                $state['collections'][] = [
                    'handle' => $collection->handle(),
                    'title' => $collection->title(),
                    'entries_count' => $collection->queryEntries()->count(),
                    'blueprint' => $collection->entryBlueprint()?->handle(),
                ];
            }

            // Taxonomies
            $taxonomies = Taxonomy::all();
            foreach ($taxonomies as $taxonomy) {
                $state['taxonomies'][] = [
                    'handle' => $taxonomy->handle(),
                    'title' => $taxonomy->title(),
                    'terms_count' => $taxonomy->queryTerms()->count(),
                ];
            }

            // Users and roles
            $state['users_count'] = User::all()->count();
            $roles = Role::all();
            foreach ($roles as $role) {
                $state['roles'][] = [
                    'handle' => $role->handle(),
                    'title' => $role->title(),
                    'permissions_count' => count($role->permissions()),
                ];
            }

            // Asset containers
            $containers = AssetContainer::all();
            foreach ($containers as $container) {
                $state['asset_containers'][] = [
                    'handle' => $container->handle(),
                    'title' => $container->title(),
                    'disk' => $container->disk(),
                    'assets_count' => $container->assets()->count(),
                ];
            }

            // Global sets
            $globalSets = GlobalSet::all();
            foreach ($globalSets as $globalSet) {
                $state['global_sets'][] = [
                    'handle' => $globalSet->handle(),
                    'title' => $globalSet->title(),
                ];
            }

            // Sites
            $sites = Site::all();
            foreach ($sites as $site) {
                $state['sites'][] = [
                    'handle' => $site->handle(),
                    'name' => $site->name(),
                    'url' => $site->url(),
                    'locale' => $site->locale(),
                ];
            }

        } catch (\Exception $e) {
            $state['error'] = 'Could not retrieve some system information: ' . $e->getMessage();
        }

        return $state;
    }

    /**
     * Get dynamic tool information by introspecting router classes.
     *
     * @return array<string, mixed>
     */
    protected function getDynamicToolInformation(): array
    {
        $toolInformation = [];

        $routerClasses = [
            'content' => 'Cboxdk\\StatamicMcp\\Mcp\\Tools\\Routers\\ContentRouter',
            'blueprints' => 'Cboxdk\\StatamicMcp\\Mcp\\Tools\\Routers\\BlueprintsRouter',
            'structures' => 'Cboxdk\\StatamicMcp\\Mcp\\Tools\\Routers\\StructuresRouter',
            'assets' => 'Cboxdk\\StatamicMcp\\Mcp\\Tools\\Routers\\AssetsRouter',
            'users' => 'Cboxdk\\StatamicMcp\\Mcp\\Tools\\Routers\\UsersRouter',
            'system' => 'Cboxdk\\StatamicMcp\\Mcp\\Tools\\Routers\\SystemRouter',
        ];

        foreach ($routerClasses as $domain => $className) {
            try {
                if (class_exists($className)) {
                    $reflection = new ReflectionClass($className);
                    $instance = $reflection->newInstance();

                    // Get actions through reflection
                    if ($reflection->hasMethod('getActions')) {
                        $actionsMethod = $reflection->getMethod('getActions');
                        $actionsMethod->setAccessible(true);
                        $actions = $actionsMethod->invoke($instance);
                    } else {
                        $actions = [];
                    }

                    // Get types through reflection
                    if ($reflection->hasMethod('getTypes')) {
                        $typesMethod = $reflection->getMethod('getTypes');
                        $typesMethod->setAccessible(true);
                        $types = $typesMethod->invoke($instance);
                    } else {
                        $types = [];
                    }

                    $toolInformation[$domain] = [
                        'tool_name' => "statamic.{$domain}",
                        'class_name' => $className,
                        'actions' => $actions,
                        'types' => $types,
                        'action_count' => count($actions),
                        'type_count' => count($types),
                        'examples' => $this->generateActionExamples($domain, $actions),
                        'parameter_mapping' => $this->extractParameterMapping($reflection), // @phpstan-ignore-line
                    ];
                }
            } catch (\Exception $e) {
                $toolInformation[$domain] = [
                    'error' => "Could not introspect {$className}: " . $e->getMessage(),
                ];
            }
        }

        return $toolInformation;
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

    // ========== ENHANCED HELPER METHODS ==========

    /**
     * Calculate complexity based on intent and complexity factors.
     */
    protected function calculateComplexity(string $intent, int $complexityFactors): string
    {
        if ($complexityFactors >= 2) {
            return 'high';
        }

        if ($complexityFactors >= 1) {
            return 'medium';
        }

        // Use original assessment as fallback
        return $this->assessComplexity($intent);
    }

    /**
     * Convert confidence score to descriptive level.
     */
    protected function getConfidenceLevel(float $confidence): string
    {
        return match (true) {
            $confidence >= 0.8 => 'high',
            $confidence >= 0.6 => 'medium',
            $confidence >= 0.4 => 'low',
            default => 'very_low'
        };
    }

    /**
     * Generate intent suggestions based on scores.
     *
     * @param  array<string, mixed>  $scores
     *
     * @return array<int, array<string, mixed>>
     */
    protected function generateIntentSuggestions(string $intent, array $scores): array
    {
        $suggestions = [];

        // Sort scores in descending order
        arsort($scores);

        // Take top 3 suggestions
        $topScores = array_slice($scores, 0, 3, true);

        foreach ($topScores as $pattern => $score) {
            if ($score > 0) {
                $suggestions[] = [
                    'pattern' => $pattern,
                    'score' => $score,
                    'description' => $this->getPatternDescription($pattern),
                    'example_intent' => $this->getExampleIntent($pattern),
                ];
            }
        }

        return $suggestions;
    }

    /**
     * Get description for a pattern.
     */
    protected function getPatternDescription(string $pattern): string
    {
        $descriptions = [
            'content_management' => 'Creating, editing, and managing website content',
            'structure_setup' => 'Configuring content schemas and data structures',
            'system_maintenance' => 'System health, performance, and maintenance tasks',
            'user_management' => 'Managing users, roles, and permissions',
            'asset_management' => 'File and media organization and management',
            'analysis_exploration' => 'Exploring and analyzing system configuration',
        ];

        return $descriptions[$pattern] ?? 'General system operations';
    }

    /**
     * Get example intent for a pattern.
     */
    protected function getExampleIntent(string $pattern): string
    {
        $examples = [
            'content_management' => 'create blog entries',
            'structure_setup' => 'configure product blueprint',
            'system_maintenance' => 'clear system cache',
            'user_management' => 'manage user roles',
            'asset_management' => 'organize image library',
            'analysis_exploration' => 'analyze current setup',
        ];

        return $examples[$pattern] ?? 'general system task';
    }

    /**
     * Generate action examples for a domain.
     *
     * @param  array<string, mixed>  $actions
     *
     * @return array<string, mixed>
     */
    protected function generateActionExamples(string $domain, array $actions): array
    {
        $examples = [];

        foreach ($actions as $action => $actionConfig) {
            if (isset($actionConfig['examples'])) {
                $examples[$action] = $actionConfig['examples'];
            } else {
                // Generate basic examples
                $examples[$action] = [
                    ['action' => $action, 'type' => $this->getDefaultType($domain)],
                ];
            }
        }

        return $examples;
    }

    /**
     * Get default type for a domain.
     */
    protected function getDefaultType(string $domain): string
    {
        $defaultTypes = [
            'content' => 'entry',
            'blueprints' => 'collections',
            'structures' => 'collection',
            'assets' => 'container',
            'users' => 'user',
            'system' => 'system',
        ];

        return $defaultTypes[$domain] ?? 'general';
    }

    /**
     * Extract parameter mapping from reflection.
     *
     *
     * @phpstan-param ReflectionClass<object> $reflection
     *
     * @return array<string, mixed>
     */
    protected function extractParameterMapping(ReflectionClass $reflection): array
    {
        $mapping = [];

        try {
            if ($reflection->hasMethod('defineSchema')) {
                $schemaMethod = $reflection->getMethod('defineSchema');
                $schemaMethod->setAccessible(true);

                // This is a simplified extraction - in a real implementation,
                // you might want to parse the actual schema definition
                $mapping['schema_available'] = true;
                $mapping['common_parameters'] = [
                    'action' => 'required',
                    'type' => 'conditional',
                    'dry_run' => 'optional',
                    'confirm' => 'optional',
                ];
            }
        } catch (\Exception $e) {
            $mapping['error'] = 'Could not extract parameter mapping: ' . $e->getMessage();
        }

        return $mapping;
    }

    /**
     * Get enhanced tool recommendations with system state context.
     *
     * @param  array<string, mixed>  $intentAnalysis
     * @param  array<string, mixed>  $systemState
     *
     * @return array<string, mixed>
     */
    protected function getEnhancedToolRecommendations(array $intentAnalysis, string $expertiseLevel, array $systemState): array
    {
        $baseRecommendations = $this->getToolRecommendations($intentAnalysis, $expertiseLevel);

        // Add context-specific enhancements
        $primaryDomain = $intentAnalysis['domains']['primary_domain'] ?? 'general';

        // Add system state context
        $baseRecommendations['system_context'] = $this->getSystemContextRecommendations($primaryDomain, $systemState);

        // Add confidence-based guidance
        $baseRecommendations['confidence_guidance'] = $this->getConfidenceBasedGuidance($intentAnalysis['confidence']);

        return $baseRecommendations;
    }

    /**
     * Get system context recommendations.
     *
     * @param  array<string, mixed>  $systemState
     *
     * @return array<string, mixed>
     */
    protected function getSystemContextRecommendations(string $domain, array $systemState): array
    {
        $context = [];

        switch ($domain) {
            case 'content':
                $context['collections_available'] = count($systemState['collections']);
                $context['suggestions'] = count($systemState['collections']) > 0
                    ? 'Start by exploring existing collections with statamic.content list'
                    : 'No collections found - consider setting up content structure first';
                break;

            case 'users':
                $context['users_count'] = $systemState['users_count'];
                $context['roles_available'] = count($systemState['roles']);
                $context['suggestions'] = $systemState['users_count'] > 0
                    ? 'Review existing users and roles with statamic.users list'
                    : 'No users found - start with user creation workflow';
                break;

            case 'assets':
                $context['containers_available'] = count($systemState['asset_containers']);
                $context['suggestions'] = count($systemState['asset_containers']) > 0
                    ? 'Explore existing asset containers with statamic.assets list'
                    : 'Set up asset containers first with statamic.assets';
                break;

            default:
                $context['general'] = 'System state loaded successfully';
                break;
        }

        return $context;
    }

    /**
     * Get confidence-based guidance.
     *
     * @return array<string, mixed>
     */
    protected function getConfidenceBasedGuidance(float $confidence): array
    {
        return match (true) {
            $confidence >= 0.8 => [
                'level' => 'high',
                'guidance' => 'Intent clearly identified - proceed with recommended tools',
                'next_steps' => 'Follow the primary tool recommendation',
            ],
            $confidence >= 0.6 => [
                'level' => 'medium',
                'guidance' => 'Intent likely identified - verify with discovery tools',
                'next_steps' => 'Use help actions to confirm tool capabilities',
            ],
            $confidence >= 0.4 => [
                'level' => 'low',
                'guidance' => 'Intent partially identified - explore multiple options',
                'next_steps' => 'Try both primary and secondary tool recommendations',
            ],
            default => [
                'level' => 'very_low',
                'guidance' => 'Intent unclear - use discovery and exploration tools',
                'next_steps' => 'Start with statamic.system.discover and help actions',
            ]
        };
    }

    /**
     * Get concrete workflow guidance with actual examples.
     *
     * @param  array<string, mixed>  $intentAnalysis
     * @param  array<string, mixed>  $systemState
     *
     * @return array<string, mixed>
     */
    protected function getConcreteWorkflowGuidance(array $intentAnalysis, string $explorationType, array $systemState): array
    {
        $baseGuidance = $this->getWorkflowGuidance($intentAnalysis, $explorationType);

        // Add concrete examples based on system state
        $pattern = $intentAnalysis['classified_pattern'];
        $concreteExamples = $this->generateConcreteExamples($pattern, $systemState);

        return array_merge($baseGuidance, [
            'concrete_examples' => $concreteExamples,
            'system_specific_notes' => $this->getSystemSpecificNotes($pattern, $systemState),
        ]);
    }

    /**
     * Generate concrete examples based on system state.
     *
     * @param  array<string, mixed>  $systemState
     *
     * @return array<string, mixed>
     */
    protected function generateConcreteExamples(string $pattern, array $systemState): array
    {
        $examples = [];

        switch ($pattern) {
            case 'content_management':
                if (! empty($systemState['collections'])) {
                    $firstCollection = $systemState['collections'][0];
                    $examples['list_content'] = [
                        'action' => 'list',
                        'type' => 'entry',
                        'collection' => $firstCollection['handle'],
                        'description' => "List entries in '{$firstCollection['title']}' collection",
                    ];
                }
                break;

            case 'user_management':
                if (! empty($systemState['roles'])) {
                    $firstRole = $systemState['roles'][0];
                    $examples['assign_role'] = [
                        'action' => 'assign_role',
                        'type' => 'user',
                        'role' => $firstRole['handle'],
                        'description' => "Assign '{$firstRole['title']}' role to a user",
                    ];
                }
                break;

            case 'asset_management':
                if (! empty($systemState['asset_containers'])) {
                    $firstContainer = $systemState['asset_containers'][0];
                    $examples['list_assets'] = [
                        'action' => 'list',
                        'type' => 'asset',
                        'container' => $firstContainer['handle'],
                        'description' => "List assets in '{$firstContainer['title']}' container",
                    ];
                }
                break;
        }

        return $examples;
    }

    /**
     * Get system-specific notes.
     *
     * @param  array<string, mixed>  $systemState
     *
     * @return list<string>
     */
    protected function getSystemSpecificNotes(string $pattern, array $systemState): array
    {
        $notes = [];

        switch ($pattern) {
            case 'content_management':
                $notes[] = sprintf('%d collections available in your system', count($systemState['collections']));
                if (count($systemState['collections']) > 5) {
                    $notes[] = 'Consider using filters to narrow down collection selection';
                }
                break;

            case 'user_management':
                $notes[] = sprintf('%d users and %d roles in your system',
                    $systemState['users_count'],
                    count($systemState['roles'])
                );
                break;

            case 'asset_management':
                $notes[] = sprintf('%d asset containers configured', count($systemState['asset_containers']));
                break;
        }

        return $notes;
    }

    /**
     * Get enhanced capability overview with dynamic information.
     *
     * @return array<string, mixed>
     */
    protected function getEnhancedCapabilityOverview(): array
    {
        $baseOverview = $this->getCapabilityOverview();
        $toolInfo = $this->getDynamicToolInformation();

        // Enhance with dynamic tool information
        foreach ($toolInfo as $domain => $info) {
            if (isset($info['actions'])) {
                $baseOverview['dynamic_capabilities'][$domain] = [
                    'actions_available' => array_keys($info['actions']),
                    'action_count' => $info['action_count'],
                    'examples_available' => ! empty($info['examples']),
                ];
            }
        }

        return $baseOverview;
    }

    /**
     * Get contextual learning path with system state integration.
     *
     * @param  array<string, mixed>  $systemState
     *
     * @return array<string, mixed>
     */
    protected function getContextualLearningPath(string $expertiseLevel, string $intent, array $systemState): array
    {
        $basePath = $this->getLearningPath($expertiseLevel, $intent);

        // Add contextual recommendations based on system state
        $contextualTips = [];

        if (count($systemState['collections']) === 0) {
            $contextualTips[] = 'Your system has no collections - start with structure setup';
        }

        if ($systemState['users_count'] <= 1) {
            $contextualTips[] = 'Consider setting up additional users and roles';
        }

        if (count($systemState['asset_containers']) === 0) {
            $contextualTips[] = 'No asset containers configured - consider media management setup';
        }

        return array_merge($basePath, [
            'contextual_tips' => $contextualTips,
            'system_readiness' => $this->assessSystemReadiness($systemState),
        ]);
    }

    /**
     * Assess system readiness for different operations.
     *
     * @param  array<string, mixed>  $systemState
     *
     * @return array<string, mixed>
     */
    protected function assessSystemReadiness(array $systemState): array
    {
        return [
            'content_ready' => count($systemState['collections']) > 0,
            'user_management_ready' => count($systemState['roles']) > 0,
            'asset_management_ready' => count($systemState['asset_containers']) > 0,
            'multi_site_ready' => count($systemState['sites']) > 1,
            'overall_readiness' => $this->calculateOverallReadiness($systemState),
        ];
    }

    /**
     * Calculate overall system readiness score.
     *
     * @param  array<string, mixed>  $systemState
     */
    protected function calculateOverallReadiness(array $systemState): float
    {
        $criteria = [
            count($systemState['collections']) > 0,
            count($systemState['roles']) > 0,
            count($systemState['asset_containers']) > 0,
            $systemState['users_count'] > 0,
        ];

        $readyCount = count(array_filter($criteria));

        return $readyCount / count($criteria);
    }

    /**
     * Create enhanced metadata with system information.
     *
     * @return array<string, mixed>
     */
    protected function createEnhancedMetadata(): array
    {
        $baseMeta = $this->createMetadata();

        return array_merge($baseMeta, [
            'version' => '2.0.0',
            'features' => [
                'dynamic_discovery',
                'enhanced_intent_analysis',
                'system_state_integration',
                'concrete_examples',
                'confidence_scoring',
            ],
            'enhancement_timestamp' => now()->toISOString(),
        ]);
    }
}
